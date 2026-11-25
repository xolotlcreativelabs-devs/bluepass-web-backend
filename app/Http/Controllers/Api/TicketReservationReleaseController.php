<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TicketReservationReleaseController extends Controller
{
    /**
     * Cancelar una reserva y liberar sus boletos.
     */
    public function destroy(
        Request $request,
        string $eventId,
        string $reservationId,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'Debes iniciar sesion.',
            ], 401);
        }

        if ($role !== 'customer') {
            return response()->json([
                'message' => 'Solo los compradores pueden cancelar reservas.',
            ], 403);
        }

        foreach ([$eventId, $reservationId] as $id) {
            if (!$this->validId($id)) {
                return response()->json([
                    'message' => 'La reserva no existe.',
                ], 404);
            }
        }

        try {
            $eventDocument = $firestore
                ->collection('events')
                ->document($eventId);

            $reservationDocument = $eventDocument
                ->collection('reservations')
                ->document($reservationId);

            $result = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $firestore,
                    $eventDocument,
                    $reservationDocument,
                    $reservationId,
                    $uid
                ): array {
                    $reservationSnapshot = $transaction->snapshot(
                        $reservationDocument
                    );

                    if (!$reservationSnapshot->exists()) {
                        return [
                            'code' => 404,
                            'message' => 'La reserva no existe.',
                        ];
                    }

                    $reservation = $reservationSnapshot->data();

                    if (!is_array($reservation)) {
                        throw new RuntimeException(
                            'Los datos de la reserva son invalidos.'
                        );
                    }

                    if (($reservation['userId'] ?? null) !== $uid) {
                        return [
                            'code' => 404,
                            'message' => 'La reserva no existe.',
                        ];
                    }

                    $status = $reservation['status'] ?? null;

                    // No liberar el inventario dos veces.
                    if (in_array(
                        $status,
                        ['cancelled', 'expired'],
                        true
                    )) {
                        return [
                            'code' => 200,
                            'message' => 'La reserva ya estaba liberada.',
                            'reservationId' => $reservationId,
                            'released' => false,
                            'status' => $status,
                        ];
                    }

                    if ($status !== 'active') {
                        return [
                            'code' => 409,
                            'message' => 'La reserva no se puede cancelar.',
                        ];
                    }

                    $ticketTypeId = $reservation['ticketTypeId'] ?? null;
                    $quantity = $reservation['quantity'] ?? null;

                    if (
                        !$this->validId($ticketTypeId) ||
                        !is_int($quantity) ||
                        $quantity < 1
                    ) {
                        throw new RuntimeException(
                            'La reserva contiene datos invalidos.'
                        );
                    }

                    $ticketDocument = $eventDocument
                        ->collection('ticketTypes')
                        ->document($ticketTypeId);

                    $ticketSnapshot = $transaction->snapshot(
                        $ticketDocument
                    );

                    if (!$ticketSnapshot->exists()) {
                        throw new RuntimeException(
                            'No se encontro el inventario de la reserva.'
                        );
                    }

                    $ticket = $ticketSnapshot->data();

                    if (!is_array($ticket)) {
                        throw new RuntimeException(
                            'Los datos del inventario son invalidos.'
                        );
                    }

                    $capacity = $ticket['capacity'] ?? null;
                    $sold = $ticket['sold'] ?? null;
                    $reserved = $ticket['reserved'] ?? null;

                    if (
                        !is_int($capacity) ||
                        !is_int($sold) ||
                        !is_int($reserved) ||
                        $capacity < 0 ||
                        $sold < 0 ||
                        $reserved < $quantity ||
                        $sold > $capacity ||
                        $reserved > ($capacity - $sold)
                    ) {
                        throw new RuntimeException(
                            'El inventario es inconsistente.'
                        );
                    }

                    // Consultar el pedido antes de modificar documentos.
                    $orderDocument = null;
                    $orderId = $reservation['orderId'] ?? null;

                    if ($orderId !== null) {
                        if (!$this->validId($orderId)) {
                            throw new RuntimeException(
                                'El identificador del pedido es invalido.'
                            );
                        }

                        $orderDocument = $firestore
                            ->collection('orders')
                            ->document($orderId);

                        $orderSnapshot = $transaction->snapshot(
                            $orderDocument
                        );

                        if (!$orderSnapshot->exists()) {
                            throw new RuntimeException(
                                'No se encontro el pedido de la reserva.'
                            );
                        }

                        $order = $orderSnapshot->data();

                        if (
                            !is_array($order) ||
                            ($order['userId'] ?? null) !== $uid ||
                            ($order['eventId'] ?? null) !==
                                ($reservation['eventId'] ?? null) ||
                            ($order['reservationId'] ?? null) !== $reservationId
                        ) {
                            throw new RuntimeException(
                                'El pedido no coincide con la reserva.'
                            );
                        }

                        // No liberar una reserva asociada a un
                        // pedido que ya dejo de estar pendiente.
                        if (($order['status'] ?? null) !== 'pending') {
                            return [
                                'code' => 409,
                                'message' => 'El pedido ya no permite cancelar su reserva.',
                            ];
                        }
                    }

                    $expiresAt = $this->readTimestamp(
                        $reservation['expiresAt'] ?? null
                    );

                    if ($expiresAt === null) {
                        throw new RuntimeException(
                            'La reserva no tiene vencimiento valido.'
                        );
                    }

                    $now = new DateTimeImmutable();
                    $timestamp = new Timestamp($now);

                    $newStatus = $now >= $expiresAt
                        ? 'expired'
                        : 'cancelled';

                    // Actualizar el inventario solo una vez.
                    $transaction->update($ticketDocument, [
                        [
                            'path' => 'reserved',
                            'value' => $reserved - $quantity,
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => $timestamp,
                        ],
                    ]);

                    // Cerrar la reserva.
                    $transaction->update($reservationDocument, [
                        [
                            'path' => 'status',
                            'value' => $newStatus,
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => $timestamp,
                        ],
                    ]);

                    // Cerrar tambien el pedido asociado.
                    if ($orderDocument !== null) {
                        $transaction->update($orderDocument, [
                            [
                                'path' => 'status',
                                'value' => $newStatus,
                            ],
                            [
                                'path' => 'updatedAt',
                                'value' => $timestamp,
                            ],
                        ]);
                    }

                    return [
                        'code' => 200,
                        'message' => 'Reserva liberada correctamente.',
                        'reservationId' => $reservationId,
                        'released' => true,
                        'status' => $newStatus,
                        'orderId' => $orderId,
                        'inventory' => [
                            'capacity' => $capacity,
                            'sold' => $sold,
                            'reserved' => $reserved - $quantity,
                            'available' => $capacity
                                - $sold
                                - ($reserved - $quantity),
                        ],
                    ];
                }
            );

            $statusCode = $result['code'] ?? 500;
            unset($result['code']);

            return response()->json($result, $statusCode);
        } catch (Throwable $exception) {
            Log::error('Error al liberar reserva BluePass', [
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible liberar la reserva.',
            ], 500);
        }
    }

    /**
     * Validar identificadores de documentos.
     */
    private function validId(mixed $id): bool
    {
        return is_string($id) &&
            preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id) === 1;
    }

    /**
     * Convertir una fecha almacenada en Firestore.
     */
    private function readTimestamp(
        mixed $value
    ): ?DateTimeImmutable {
        if ($value instanceof Timestamp) {
            $value = $value->get();
        }

        if (!$value instanceof DateTimeInterface) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value);
    }
}
