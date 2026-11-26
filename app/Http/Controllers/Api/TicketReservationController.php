<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MultiTicketReservationService;
use App\Services\MultiTicketSelectionValidator;
use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TicketReservationController extends Controller
{
    private const RESERVATION_MINUTES = 10;
    private const MAX_TICKETS_PER_RESERVATION = 10;

    /**
     * Crear una reserva temporal de boletos.
     * Acepta una categoria o varias categorias.
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
                'message' => 'Debes iniciar sesion para reservar boletos.',
            ], 401);
        }

        if ($role !== 'customer') {
            return response()->json([
                'message' => 'Solo los compradores pueden reservar boletos.',
            ], 403);
        }

        if (
            !preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $eventId
            )
        ) {
            return response()->json([
                'message' => 'El evento no existe.',
            ], 404);
        }

        // Las reservas multiples utilizan el servicio
        // que controla todas las categorias en una transaccion.
        if ($request->exists('items')) {
            return $this->storeMultiple(
                $request,
                $eventId,
                $uid,
                $firestore
            );
        }

        // Mantener el funcionamiento tradicional.
        return $this->storeSingle(
            $request,
            $eventId,
            $uid,
            $firestore
        );
    }

    /**
     * Crear una reserva de varias categorias.
     */
    private function storeMultiple(
        Request $request,
        string $eventId,
        string $uid,
        FirestoreClient $firestore
    ): JsonResponse {
        try {
            $service = new MultiTicketReservationService(
                $firestore,
                new MultiTicketSelectionValidator()
            );

            $result = $service->create(
                $eventId,
                $uid,
                $request->all()
            );

            $statusCode = $result['code'] ?? 500;

            unset($result['code']);

            return response()->json(
                $result,
                $statusCode
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Los datos de la reserva no son validos.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => 'Los datos de la reserva no son validos.',
            ], 422);
        } catch (Throwable $exception) {
            Log::error(
                'Error al crear reserva multiple BluePass',
                [
                    'exception' => get_class($exception),
                ]
            );

            return response()->json([
                'message' => 'No fue posible crear la reserva.',
            ], 500);
        }
    }

    /**
     * Crear una reserva tradicional de una categoria.
     */
    private function storeSingle(
        Request $request,
        string $eventId,
        string $uid,
        FirestoreClient $firestore
    ): JsonResponse {
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

        $validator = Validator::make(
            $request->all(),
            [
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
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la reserva no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $ticketTypeId = $data['ticketTypeId'];
        $quantity = (int) $data['quantity'];

        try {
            $userDocument = $firestore
                ->collection('users')
                ->document($uid);

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
                    $userDocument,
                    $eventDocument,
                    $ticketDocument,
                    $reservationDocument,
                    $eventId,
                    $ticketTypeId,
                    $quantity,
                    $uid
                ): array {
                    // Leer todos los documentos antes de escribir.
                    $userSnapshot = $transaction->snapshot(
                        $userDocument
                    );

                    $eventSnapshot = $transaction->snapshot(
                        $eventDocument
                    );

                    $ticketSnapshot = $transaction->snapshot(
                        $ticketDocument
                    );

                    // Comprobar la cuenta dentro de la transaccion.
                    if (!$userSnapshot->exists()) {
                        return [
                            'code' => 403,
                            'message' => 'La cuenta del comprador no esta disponible.',
                        ];
                    }

                    $user = $userSnapshot->data();

                    if (
                        !is_array($user) ||
                        ($user['authSubject'] ?? null) !== $uid ||
                        ($user['role'] ?? null) !== 'customer' ||
                        ($user['status'] ?? null) !== 'active'
                    ) {
                        return [
                            'code' => 403,
                            'message' => 'La cuenta del comprador no esta activa.',
                        ];
                    }

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
                            'message' => 'El tipo de boleto no esta disponible.',
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
                            'message' => 'El boleto no tiene un periodo de venta valido.',
                        ];
                    }

                    if ($now >= $eventStart) {
                        return [
                            'code' => 409,
                            'message' => 'El evento ya comenzo.',
                        ];
                    }

                    if ($now < $saleStart) {
                        return [
                            'code' => 409,
                            'message' => 'La venta de boletos todavia no comienza.',
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

                    $price = $ticket['price'] ?? null;

                    if (!is_int($price) || $price < 0) {
                        throw new RuntimeException(
                            'El precio del boleto no es valido.'
                        );
                    }

                    $expiresAt = $now->modify(
                        '+' . self::RESERVATION_MINUTES . ' minutes'
                    );

                    $timestamp = new Timestamp($now);

                    // Guardar la reserva temporal.
                    $reservation = [
                        'eventId' => $eventId,
                        'ticketTypeId' => $ticketTypeId,
                        'userId' => $uid,
                        'quantity' => $quantity,
                        'unitPrice' => $price,
                        'currency' => 'MXN',
                        'status' => 'active',
                        'createdAt' => $timestamp,
                        'updatedAt' => $timestamp,
                        'expiresAt' => new Timestamp($expiresAt),
                    ];

                    // La reserva y el inventario se actualizan
                    // en una sola transaccion.
                    $transaction->create(
                        $reservationDocument,
                        $reservation
                    );

                    $transaction->update(
                        $ticketDocument,
                        [
                            [
                                'path' => 'reserved',
                                'value' => $reserved + $quantity,
                            ],
                            [
                                'path' => 'updatedAt',
                                'value' => $timestamp,
                            ],
                        ]
                    );

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
        } catch (Throwable $exception) {
            Log::error(
                'Error al crear reserva temporal BluePass',
                [
                    'exception' => get_class($exception),
                ]
            );

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
