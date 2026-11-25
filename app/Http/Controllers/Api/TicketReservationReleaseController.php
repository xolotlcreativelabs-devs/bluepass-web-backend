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
                'message' => 'Debes iniciar sesión.',
            ], 401);
        }

        if ($role !== 'customer') {
            return response()->json([
                'message' => 'Solo los compradores pueden cancelar reservas.',
            ], 403);
        }

        foreach ([$eventId, $reservationId] as $id) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) {
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

                    // Solo el comprador propietario puede cancelar.
                    if (($reservation['userId'] ?? null) !== $uid) {
                        return [
                            'code' => 404,
                            'message' => 'La reserva no existe.',
                        ];
                    }

                    $status = $reservation['status'] ?? null;

                    // No liberar inventario dos veces.
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
                        !is_string($ticketTypeId) ||
                        !preg_match(
                            '/^[A-Za-z0-9_-]{1,128}$/D',
                            $ticketTypeId
                        ) ||
                        !is_int($quantity) ||
                        $quantity < 1
                    ) {
                        throw new RuntimeException(
                            'La reserva contiene datos invalidos.'
                        );
                    }

                    // Leer el inventario dentro de la transaccion.
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

                    $expiresAt = $this->readTimestamp(
                        $reservation['expiresAt'] ?? null
                    );

                    if ($expiresAt === null) {
                        throw new RuntimeException(
                            'La reserva no tiene vencimiento valido.'
                        );
                    }

                    $now = new DateTimeImmutable();

                    $newStatus = $now >= $expiresAt
                        ? 'expired'
                        : 'cancelled';

                    // Liberar el inventario y cerrar la reserva.
                    $transaction->update($ticketDocument, [
                        [
                            'path' => 'reserved',
                            'value' => $reserved - $quantity,
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => new Timestamp($now),
                        ],
                    ]);

                    $transaction->update($reservationDocument, [
                        [
                            'path' => 'status',
                            'value' => $newStatus,
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => new Timestamp($now),
                        ],
                    ]);

                    return [
                        'code' => 200,
                        'message' => 'Reserva liberada correctamente.',
                        'reservationId' => $reservationId,
                        'released' => true,
                        'status' => $newStatus,
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
        } catch (Throwable $e) {
            Log::error('Error al liberar reserva BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible liberar la reserva.',
            ], 500);
        }
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
