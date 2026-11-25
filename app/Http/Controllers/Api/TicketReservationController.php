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
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class TicketReservationController extends Controller
{
    private const RESERVATION_MINUTES = 10;
    private const MAX_TICKETS_PER_RESERVATION = 10;

    /**
     * Crear una reserva temporal de boletos.
     */
    public function store(
        Request $request,
        string $eventId,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'Debes iniciar sesión para reservar boletos.',
            ], 401);
        }

        if ($role !== 'customer') {
            return response()->json([
                'message' => 'Solo los compradores pueden reservar boletos.',
            ], 403);
        }

        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $eventId)) {
            return response()->json([
                'message' => 'El evento no existe.',
            ], 404);
        }

        $allowedFields = [
            'ticketTypeId',
            'quantity',
        ];

        $unexpectedFields = array_diff(
            array_keys($request->all()),
            $allowedFields
        );

        if ($unexpectedFields !== []) {
            return response()->json([
                'message' => 'Se enviaron campos no autorizados.',
                'errors' => [
                    'fields' => array_values($unexpectedFields),
                ],
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'ticketTypeId' => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9_-]{1,128}$/D',
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:' . self::MAX_TICKETS_PER_RESERVATION,
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la reserva no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $ticketTypeId = $data['ticketTypeId'];
        $quantity = (int) $data['quantity'];

        try {
            $eventDocument = $firestore
                ->collection('events')
                ->document($eventId);

            $ticketDocument = $eventDocument
                ->collection('ticketTypes')
                ->document($ticketTypeId);

            $reservationDocument = $eventDocument
                ->collection('reservations')
                ->newDocument();

            $result = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $eventDocument,
                    $ticketDocument,
                    $reservationDocument,
                    $eventId,
                    $ticketTypeId,
                    $quantity,
                    $uid
                ): array {
                    // Leer el evento y el inventario antes de escribir.
                    $eventSnapshot = $transaction->snapshot(
                        $eventDocument
                    );

                    $ticketSnapshot = $transaction->snapshot(
                        $ticketDocument
                    );

                    if (!$eventSnapshot->exists()) {
                        return [
                            'code' => 404,
                            'message' => 'El evento no existe.',
                        ];
                    }

                    $event = $eventSnapshot->data();

                    if (
                        !is_array($event) ||
                        ($event['status'] ?? null) !== 'published'
                    ) {
                        return [
                            'code' => 404,
                            'message' => 'El evento no existe.',
                        ];
                    }

                    if (!$ticketSnapshot->exists()) {
                        return [
                            'code' => 404,
                            'message' => 'El tipo de boleto no existe.',
                        ];
                    }

                    $ticket = $ticketSnapshot->data();

                    if (
                        !is_array($ticket) ||
                        ($ticket['status'] ?? null) !== 'active'
                    ) {
                        return [
                            'code' => 409,
                            'message' => 'El tipo de boleto no está disponible.',
                        ];
                    }

                    $now = new DateTimeImmutable();

                    $eventStart = $this->readTimestamp(
                        $event['date'] ?? null
                    );

                    $saleStart = $this->readTimestamp(
                        $ticket['saleStartAt'] ?? null
                    );

                    $saleEnd = $this->readTimestamp(
                        $ticket['saleEndAt'] ?? null
                    );

                    if (
                        $eventStart === null ||
                        $saleStart === null ||
                        $saleEnd === null ||
                        $saleStart >= $saleEnd
                    ) {
                        return [
                            'code' => 409,
                            'message' => 'El boleto no tiene un periodo de venta válido.',
                        ];
                    }

                    if ($now >= $eventStart) {
                        return [
                            'code' => 409,
                            'message' => 'El evento ya comenzó.',
                        ];
                    }

                    if ($now < $saleStart) {
                        return [
                            'code' => 409,
                            'message' => 'La venta de boletos todavía no comienza.',
                        ];
                    }

                    if ($now >= $saleEnd) {
                        return [
                            'code' => 409,
                            'message' => 'La venta de boletos ha terminado.',
                        ];
                    }

                    $capacity = $ticket['capacity'] ?? null;
                    $sold = $ticket['sold'] ?? null;
                    $reserved = $ticket['reserved'] ?? 0;

                    if (
                        !is_int($capacity) ||
                        !is_int($sold) ||
                        !is_int($reserved) ||
                        $capacity < 1 ||
                        $sold < 0 ||
                        $reserved < 0 ||
                        $sold > $capacity ||
                        $reserved > ($capacity - $sold)
                    ) {
                        throw new RuntimeException(
                            'El inventario de boletos es inconsistente.'
                        );
                    }

                    $available = $capacity - $sold - $reserved;

                    if ($quantity > $available) {
                        return [
                            'code' => 409,
                            'message' => 'No hay suficientes boletos disponibles.',
                            'available' => $available,
                        ];
                    }

                    $expiresAt = $now->modify(
                        '+' . self::RESERVATION_MINUTES . ' minutes'
                    );

                    $price = $ticket['price'] ?? null;

                    if (!is_int($price) || $price < 0) {
                        throw new RuntimeException(
                            'El precio del boleto no es válido.'
                        );
                    }

                    // Registrar la reserva con su vencimiento.
                    $reservation = [
                        'eventId' => $eventId,
                        'ticketTypeId' => $ticketTypeId,
                        'userId' => $uid,
                        'quantity' => $quantity,
                        'unitPrice' => $price,
                        'currency' => 'MXN',
                        'status' => 'active',
                        'createdAt' => new Timestamp($now),
                        'updatedAt' => new Timestamp($now),
                        'expiresAt' => new Timestamp($expiresAt),
                    ];

                    // Ambas escrituras pertenecen a la misma transacción.
                    $transaction->create(
                        $reservationDocument,
                        $reservation
                    );

                    $transaction->update($ticketDocument, [
                        [
                            'path' => 'reserved',
                            'value' => $reserved + $quantity,
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => new Timestamp($now),
                        ],
                    ]);

                    return [
                        'code' => 201,
                        'message' => 'Reserva temporal creada correctamente.',
                        'reservation' => [
                            'id' => $reservationDocument->id(),
                            'eventId' => $eventId,
                            'ticketTypeId' => $ticketTypeId,
                            'quantity' => $quantity,
                            'unitPrice' => $price,
                            'currency' => 'MXN',
                            'status' => 'active',
                            'expiresAt' => $expiresAt->format(
                                DateTimeInterface::ATOM
                            ),
                        ],
                        'inventory' => [
                            'capacity' => $capacity,
                            'sold' => $sold,
                            'reserved' => $reserved + $quantity,
                            'available' => $available - $quantity,
                        ],
                    ];
                }
            );

            $statusCode = $result['code'] ?? 500;

            unset($result['code']);

            return response()->json(
                $result,
                $statusCode
            );
        } catch (Throwable $e) {
            Log::error('Error al crear reserva temporal BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible crear la reserva.',
            ], 500);
        }
    }

    /**
     * Leer una fecha almacenada en Firestore.
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

        return DateTimeImmutable::createFromInterface(
            $value
        );
    }
}
