<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReservationItems;
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
     * Cancelar una reserva y liberar todos sus boletos.
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
                    $eventId,
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

                    // Ocultar reservas de otros compradores.
                    if (($reservation['userId'] ?? null) !== $uid) {
                        return [
                            'code' => 404,
                            'message' => 'La reserva no existe.',
                        ];
                    }

                    // Comparar con el evento recibido en la ruta.
                    if (
                        ($reservation['eventId'] ?? null) !==
                        $eventId
                    ) {
                        throw new RuntimeException(
                            'El evento de la reserva no coincide.'
                        );
                    }

                    $status = $reservation['status'] ?? null;

                    // No liberar inventario de reservas cerradas.
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

                    // Interpretar reservas tradicionales y multiples.
                    $items = (new ReservationItems())
                        ->fromReservation($reservation);

                    $isMultiple = array_key_exists(
                        'items',
                        $reservation
                    );

                    $ticketDocuments = [];

                    foreach ($items as $item) {
                        $typeId = $item['ticketTypeId'];

                        $ticketDocuments[$typeId] = $eventDocument
                            ->collection('ticketTypes')
                            ->document($typeId);
                    }

                    // Leer y validar todos los inventarios.
                    $inventoryUpdates = [];

                    foreach ($items as $item) {
                        $typeId = $item['ticketTypeId'];
                        $quantity = $item['quantity'];

                        $ticketSnapshot = $transaction->snapshot(
                            $ticketDocuments[$typeId]
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

                        $newReserved = $reserved - $quantity;

                        $inventoryUpdates[$typeId] = [
                            'capacity' => $capacity,
                            'sold' => $sold,
                            'reserved' => $newReserved,
                            'available' => $capacity
                                - $sold
                                - $newReserved,
                        ];
                    }

                    // Comprobar el pedido asociado, si existe.
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
                            ($order['eventId'] ?? null) !== $eventId ||
                            ($order['reservationId'] ?? null) !==
                                $reservationId
                        ) {
                            throw new RuntimeException(
                                'El pedido no coincide con la reserva.'
                            );
                        }

                        // Comprobar los tipos de los pedidos mixtos.
                        if ($isMultiple) {
                            $orderItems = (new ReservationItems())
                                ->fromReservation($order);

                            if ($orderItems !== $items) {
                                throw new RuntimeException(
                                    'Los boletos del pedido no coinciden.'
                                );
                            }
                        }

                        // Los pedidos pagados no pueden cancelarse.
                        if (($order['status'] ?? null) !== 'pending') {
                            return [
                                'code' => 409,
                                'message' =>
                                    'El pedido ya no permite cancelar su reserva.',
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

                    // Todas las escrituras son transaccionales.
                    foreach ($inventoryUpdates as $typeId => $inventory) {
                        $transaction->update(
                            $ticketDocuments[$typeId],
                            [
                                [
                                    'path' => 'reserved',
                                    'value' => $inventory['reserved'],
                                ],
                                [
                                    'path' => 'updatedAt',
                                    'value' => $timestamp,
                                ],
                            ]
                        );
                    }

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

                    $response = [
                        'code' => 200,
                        'message' => 'Reserva liberada correctamente.',
                        'reservationId' => $reservationId,
                        'released' => true,
                        'status' => $newStatus,
                        'orderId' => $orderId,
                    ];

                    if ($isMultiple) {
                        $response['inventories'] = $inventoryUpdates;
                    } else {
                        $typeId = $items[0]['ticketTypeId'];

                        $response['inventory'] =
                            $inventoryUpdates[$typeId];
                    }

                    return $response;
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
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $id
            ) === 1;
    }

    /**
     * Convertir una fecha de Firestore.
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
