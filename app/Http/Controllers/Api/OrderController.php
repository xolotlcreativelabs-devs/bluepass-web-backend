<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrderAmountCalculator;
use App\Services\OrderReservationValidator;
use App\Services\OrderSelectionValidator;
use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Exception\AbortedException;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class OrderController extends Controller
{
    private const MAX_TRANSACTION_ATTEMPTS = 3;

    /**
     * Crear un pedido a partir de una reserva vigente.
     */
    public function store(
        Request $request,
        string $eventId,
        FirestoreClient $firestore,
        OrderSelectionValidator $selectionValidator,
        OrderReservationValidator $reservationValidator,
        OrderAmountCalculator $amountCalculator
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');
        $profile = $request->attributes->get('firebase_profile');

        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'Debes iniciar sesion para crear un pedido.',
            ], 401);
        }

        if ($role !== 'customer') {
            return response()->json([
                'message' => 'Solo los compradores pueden crear pedidos.',
            ], 403);
        }

        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $eventId)) {
            return response()->json([
                'message' => 'El evento no existe.',
            ], 404);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (
            !is_string($idempotencyKey) ||
            !preg_match(
                '/^[A-Za-z0-9_-]{8,128}$/D',
                $idempotencyKey
            )
        ) {
            return response()->json([
                'message' => 'Debes proporcionar una clave de idempotencia valida.',
                'errors' => [
                    'Idempotency-Key' => [
                        'Utiliza entre 8 y 128 caracteres alfanumericos, guiones o guiones bajos.',
                    ],
                ],
            ], 422);
        }

        try {
            $selection = $selectionValidator->validate(
                $request->all()
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'La seleccion de boletos no es valida.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $reservationId = $selection['reservationId'];
        $ticketTypeId = $selection['ticketTypeId'];
        $quantity = $selection['quantity'];

        $orderId = hash(
            'sha256',
            $uid . ':' . $idempotencyKey
        );

        try {
            $eventDocument = $firestore
                ->collection('events')
                ->document($eventId);

            $ticketDocument = $eventDocument
                ->collection('ticketTypes')
                ->document($ticketTypeId);

            $reservationDocument = $eventDocument
                ->collection('reservations')
                ->document($reservationId);

            $orderDocument = $firestore
                ->collection('orders')
                ->document($orderId);

            $result = null;

            for (
                $attempt = 1;
                $attempt <= self::MAX_TRANSACTION_ATTEMPTS;
                $attempt++
            ) {
                try {
                    $result = $firestore->runTransaction(
                        function (Transaction $transaction) use (
                            $eventDocument,
                            $ticketDocument,
                            $reservationDocument,
                            $orderDocument,
                            $reservationValidator,
                            $amountCalculator,
                            $eventId,
                            $reservationId,
                            $ticketTypeId,
                            $quantity,
                            $selection,
                            $uid,
                            $profile,
                            $orderId,
                            $idempotencyKey
                        ): array {
                            // Leer antes de realizar cualquier escritura.
                            $orderSnapshot = $transaction->snapshot(
                                $orderDocument
                            );

                            $eventSnapshot = $transaction->snapshot(
                                $eventDocument
                            );

                            $ticketSnapshot = $transaction->snapshot(
                                $ticketDocument
                            );

                            $reservationSnapshot = $transaction->snapshot(
                                $reservationDocument
                            );

                            // Reutilizar el pedido si la clave ya existe.
                            if ($orderSnapshot->exists()) {
                                $existingOrder = $orderSnapshot->data();

                                if (
                                    !is_array($existingOrder) ||
                                    ($existingOrder['userId'] ?? null) !== $uid ||
                                    ($existingOrder['eventId'] ?? null) !== $eventId ||
                                    ($existingOrder['reservationId'] ?? null) !== $reservationId ||
                                    ($existingOrder['ticketTypeId'] ?? null) !== $ticketTypeId ||
                                    ($existingOrder['quantity'] ?? null) !== $quantity ||
                                    (
                                        isset($existingOrder['idempotencyKey']) &&
                                        $existingOrder['idempotencyKey'] !== $idempotencyKey
                                    )
                                ) {
                                    return [
                                        'code' => 409,
                                        'message' => 'La clave de idempotencia ya corresponde a otra solicitud.',
                                    ];
                                }

                                if (
                                    ($existingOrder['status'] ?? null) !== 'pending'
                                ) {
                                    return [
                                        'code' => 409,
                                        'message' => 'El pedido ya no esta pendiente.',
                                    ];
                                }

                                if (!$reservationSnapshot->exists()) {
                                    return [
                                        'code' => 409,
                                        'message' => 'La reserva del pedido ya no existe.',
                                    ];
                                }

                                $existingReservation = $reservationSnapshot->data();

                                if (!is_array($existingReservation)) {
                                    return [
                                        'code' => 409,
                                        'message' => 'La reserva del pedido no es valida.',
                                    ];
                                }

                                $existingValidation = $reservationValidator->validate(
                                    $selection,
                                    $existingReservation,
                                    $uid,
                                    $eventId
                                );

                                if (!$existingValidation['valid']) {
                                    return [
                                        'code' => $existingValidation['code'],
                                        'message' => $existingValidation['message'],
                                    ];
                                }

                                if (
                                    ($existingReservation['orderId'] ?? null) !== $orderId
                                ) {
                                    return [
                                        'code' => 409,
                                        'message' => 'La reserva no corresponde al pedido.',
                                    ];
                                }

                                return [
                                    'code' => 200,
                                    'message' => 'El pedido ya habia sido creado.',
                                    'idempotent' => true,
                                    'order' => $this->formatOrder(
                                        $orderId,
                                        $existingOrder
                                    ),
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
                                    'message' => 'El evento no esta publicado.',
                                ];
                            }

                            // El vendedor es obligatorio y viene del evento.
                            $sellerId = $event['sellerId'] ?? null;

                            if (
                                !is_string($sellerId) ||
                                !preg_match(
                                    '/^[A-Za-z0-9_-]{1,128}$/D',
                                    $sellerId
                                )
                            ) {
                                return [
                                    'code' => 409,
                                    'message' => 'El evento no tiene un vendedor valido.',
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

                            if (!$reservationSnapshot->exists()) {
                                return [
                                    'code' => 404,
                                    'message' => 'La reserva no existe.',
                                ];
                            }

                            $reservation = $reservationSnapshot->data();

                            if (!is_array($reservation)) {
                                return [
                                    'code' => 409,
                                    'message' => 'Los datos de la reserva no son validos.',
                                ];
                            }

                            $validation = $reservationValidator->validate(
                                $selection,
                                $reservation,
                                $uid,
                                $eventId
                            );

                            if (!$validation['valid']) {
                                return [
                                    'code' => $validation['code'],
                                    'message' => $validation['message'],
                                ];
                            }

                            if (
                                array_key_exists('orderId', $reservation) &&
                                $reservation['orderId'] !== null
                            ) {
                                return [
                                    'code' => 409,
                                    'message' => 'La reserva ya esta vinculada a un pedido.',
                                ];
                            }

                            $capacity = $ticket['capacity'] ?? null;
                            $sold = $ticket['sold'] ?? null;
                            $reserved = $ticket['reserved'] ?? null;

                            if (
                                !is_int($capacity) ||
                                !is_int($sold) ||
                                !is_int($reserved) ||
                                $capacity < 1 ||
                                $sold < 0 ||
                                $reserved < 0 ||
                                $sold > $capacity ||
                                $reserved > $capacity - $sold
                            ) {
                                throw new RuntimeException(
                                    'El inventario de boletos es inconsistente.'
                                );
                            }

                            if ($reserved < $quantity) {
                                return [
                                    'code' => 409,
                                    'message' => 'El inventario reservado es insuficiente.',
                                ];
                            }

                            // Los importes se calculan en el servidor.
                            $amounts = $amountCalculator->calculate(
                                $validation['quantity'],
                                $validation['unitPrice'],
                                $validation['currency']
                            );

                            $now = new DateTimeImmutable();
                            $timestamp = new Timestamp($now);

                            $buyerName = is_array($profile)
                                ? ($profile['name'] ?? '')
                                : '';

                            $buyerEmail = is_array($profile)
                                ? ($profile['email'] ?? '')
                                : '';

                            $items = [
                                [
                                    'ticketTypeId' => $ticketTypeId,
                                    'quantity' => $quantity,
                                    'unitPrice' => $amounts['unitPrice'],
                                    'subtotal' => $amounts['subtotal'],
                                ],
                            ];

                            $order = [
                                'userId' => $uid,
                                'eventId' => $eventId,
                                'sellerId' => $sellerId,
                                'channel' => 'online',
                                'items' => $items,
                                'idempotencyKey' => $idempotencyKey,
                                'reservationId' => $reservationId,
                                'ticketTypeId' => $ticketTypeId,
                                'quantity' => $quantity,
                                'unitPrice' => $amounts['unitPrice'],
                                'currency' => $amounts['currency'],
                                'subtotal' => $amounts['subtotal'],
                                'fees' => $amounts['fees'],
                                'total' => $amounts['total'],
                                'status' => 'pending',
                                'buyer' => [
                                    'id' => $uid,
                                    'name' => is_string($buyerName)
                                        ? $buyerName
                                        : '',
                                    'email' => is_string($buyerEmail)
                                        ? $buyerEmail
                                        : '',
                                ],
                                'expiresAt' => new Timestamp(
                                    $validation['expiresAt']
                                ),
                                'createdAt' => $timestamp,
                                'updatedAt' => $timestamp,
                            ];

                            // Ambas escrituras pertenecen a la misma transaccion.
                            $transaction->create(
                                $orderDocument,
                                $order
                            );

                            $transaction->update(
                                $reservationDocument,
                                [
                                    [
                                        'path' => 'orderId',
                                        'value' => $orderId,
                                    ],
                                    [
                                        'path' => 'updatedAt',
                                        'value' => $timestamp,
                                    ],
                                ]
                            );

                            return [
                                'code' => 201,
                                'message' => 'Pedido creado correctamente.',
                                'idempotent' => false,
                                'order' => $this->formatOrder(
                                    $orderId,
                                    $order
                                ),
                            ];
                        }
                    );

                    // La transaccion termino correctamente.
                    break;
                } catch (AbortedException $exception) {
                    if ($attempt === self::MAX_TRANSACTION_ATTEMPTS) {
                        Log::warning(
                            'Conflicto de concurrencia al crear pedido BluePass',
                            [
                                'attempts' => $attempt,
                                'exception' => get_class($exception),
                            ]
                        );

                        return response()->json([
                            'message' => 'El pedido esta siendo procesado. Intenta nuevamente con la misma clave de idempotencia.',
                        ], 503)->header('Retry-After', '1');
                    }

                    // Espera breve y variable antes de una transaccion nueva.
                    // El siguiente intento volvera a leer pedido y reserva.
                    usleep(
                        (100000 * $attempt) +
                        random_int(0, 100000)
                    );
                }
            }

            if (!is_array($result)) {
                throw new RuntimeException(
                    'La transaccion no devolvio un resultado.'
                );
            }

            $statusCode = $result['code'] ?? 500;

            unset($result['code']);

            return response()->json(
                $result,
                $statusCode
            );
        } catch (Throwable $exception) {
            Log::error('Error al crear pedido BluePass', [
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible crear el pedido.',
            ], 500);
        }
    }

    /**
     * Preparar el resumen del pedido para el comprador.
     */
    private function formatOrder(
        string $orderId,
        array $order
    ): array {
        $expiresAt = $order['expiresAt'] ?? null;

        if ($expiresAt instanceof Timestamp) {
            $expiresAt = $expiresAt->get();
        }

        return [
            'id' => $orderId,
            'eventId' => $order['eventId'] ?? null,
            'sellerId' => $order['sellerId'] ?? null,
            'channel' => $order['channel'] ?? null,
            'items' => $order['items'] ?? [],
            'reservationId' => $order['reservationId'] ?? null,
            'ticketTypeId' => $order['ticketTypeId'] ?? null,
            'quantity' => $order['quantity'] ?? null,
            'unitPrice' => $order['unitPrice'] ?? null,
            'currency' => $order['currency'] ?? null,
            'subtotal' => $order['subtotal'] ?? null,
            'fees' => $order['fees'] ?? null,
            'total' => $order['total'] ?? null,
            'status' => $order['status'] ?? null,
            'buyer' => $order['buyer'] ?? null,
            'expiresAt' => $expiresAt instanceof DateTimeInterface
                ? $expiresAt->format(DateTimeInterface::ATOM)
                : null,
        ];
    }
}
