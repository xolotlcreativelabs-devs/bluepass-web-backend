<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MultiOrderReservationValidator;
use App\Services\OrderAmountCalculator;
use App\Services\OrderReservationValidator;
use App\Services\OrderSelectionValidator;
use App\Services\ReservationItems;
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
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class OrderController extends Controller
{
    private const MAX_TRANSACTION_ATTEMPTS = 3;

    /**
     * Crear un pedido a partir de una reserva vigente.
     *
     * Acepta compras tradicionales y multiples.
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

        $idempotencyKey = $request->header(
            'Idempotency-Key'
        );

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

        $isMultiple = array_key_exists(
            'items',
            $selection
        );

        $selectedItems = $isMultiple
            ? $selection['items']
            : [
                [
                    'ticketTypeId' => $selection['ticketTypeId'],
                    'quantity' => $selection['quantity'],
                ],
            ];

        $orderId = hash(
            'sha256',
            $uid . ':' . $idempotencyKey
        );

        try {
            $userDocument = $firestore
                ->collection('users')
                ->document($uid);

            $eventDocument = $firestore
                ->collection('events')
                ->document($eventId);

            $reservationDocument = $eventDocument
                ->collection('reservations')
                ->document($reservationId);

            $orderDocument = $firestore
                ->collection('orders')
                ->document($orderId);

            // Preparar las categorias seleccionadas.
            $ticketDocuments = [];

            foreach ($selectedItems as $item) {
                $typeId = $item['ticketTypeId'];

                $ticketDocuments[$typeId] = $eventDocument
                    ->collection('ticketTypes')
                    ->document($typeId);
            }

            $result = null;

            for (
                $attempt = 1;
                $attempt <= self::MAX_TRANSACTION_ATTEMPTS;
                $attempt++
            ) {
                try {
                    $result = $firestore->runTransaction(
                        function (Transaction $transaction) use (
                            $userDocument,
                            $eventDocument,
                            $reservationDocument,
                            $orderDocument,
                            $ticketDocuments,
                            $reservationValidator,
                            $amountCalculator,
                            $eventId,
                            $reservationId,
                            $selection,
                            $selectedItems,
                            $isMultiple,
                            $uid,
                            $profile,
                            $orderId,
                            $idempotencyKey
                        ): array {
                            // Leer todos los documentos antes
                            // de realizar cualquier escritura.
                            $userSnapshot = $transaction->snapshot(
                                $userDocument
                            );

                            $orderSnapshot = $transaction->snapshot(
                                $orderDocument
                            );

                            $eventSnapshot = $transaction->snapshot(
                                $eventDocument
                            );

                            $reservationSnapshot = $transaction->snapshot(
                                $reservationDocument
                            );

                            $ticketSnapshots = [];

                            foreach (
                                $ticketDocuments as $typeId => $document
                            ) {
                                $ticketSnapshots[$typeId] =
                                    $transaction->snapshot($document);
                            }

                            // Solo las cuentas activas pueden crear pedidos.
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

                            // Comprobar si el pedido ya existe.
                            if ($orderSnapshot->exists()) {
                                $existingOrder = $orderSnapshot->data();

                                if (
                                    !is_array($existingOrder) ||
                                    ($existingOrder['userId'] ?? null) !== $uid ||
                                    ($existingOrder['eventId'] ?? null) !== $eventId ||
                                    ($existingOrder['reservationId'] ?? null) !== $reservationId ||
                                    (
                                        isset($existingOrder['idempotencyKey']) &&
                                        $existingOrder['idempotencyKey'] !== $idempotencyKey
                                    ) ||
                                    !$this->orderMatchesSelection(
                                        $existingOrder,
                                        $selectedItems,
                                        $isMultiple
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

                                $existingReservation =
                                    $reservationSnapshot->data();

                                if (!is_array($existingReservation)) {
                                    return [
                                        'code' => 409,
                                        'message' => 'La reserva del pedido no es valida.',
                                    ];
                                }

                                $existingValidation =
                                    $this->validateReservation(
                                        $selection,
                                        $existingReservation,
                                        $uid,
                                        $eventId,
                                        $reservationValidator,
                                        $isMultiple
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

                            // Comprobar que el evento exista.
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

                            // Obtener el vendedor del evento.
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

                            // Comprobar todos los tipos de boleto.
                            $inventories = [];

                            foreach ($selectedItems as $item) {
                                $typeId = $item['ticketTypeId'];

                                $snapshot = $ticketSnapshots[$typeId];

                                if (!$snapshot->exists()) {
                                    return [
                                        'code' => 404,
                                        'message' => 'El tipo de boleto no existe.',
                                    ];
                                }

                                $ticket = $snapshot->data();

                                if (
                                    !is_array($ticket) ||
                                    ($ticket['status'] ?? null) !== 'active'
                                ) {
                                    return [
                                        'code' => 409,
                                        'message' => 'El tipo de boleto no esta disponible.',
                                    ];
                                }

                                $inventories[$typeId] = $ticket;
                            }

                            // Comprobar la reserva.
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

                            // Comprobar comprador, evento,
                            // cantidades y vencimiento.
                            $validation = $this->validateReservation(
                                $selection,
                                $reservation,
                                $uid,
                                $eventId,
                                $reservationValidator,
                                $isMultiple
                            );

                            if (!$validation['valid']) {
                                return [
                                    'code' => $validation['code'],
                                    'message' => $validation['message'],
                                ];
                            }

                            // No permitir dos pedidos
                            // asociados a la misma reserva.
                            if (
                                array_key_exists('orderId', $reservation) &&
                                $reservation['orderId'] !== null
                            ) {
                                return [
                                    'code' => 409,
                                    'message' => 'La reserva ya esta vinculada a un pedido.',
                                ];
                            }

                            // Comprobar el inventario reservado
                            // de cada categoria.
                            foreach ($selectedItems as $item) {
                                $typeId = $item['ticketTypeId'];
                                $quantity = $item['quantity'];

                                $ticket = $inventories[$typeId];

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
                            }

                            // Calcular los importes desde
                            // los datos validados del servidor.
                            if ($isMultiple) {
                                $amounts =
                                    $amountCalculator->calculateItems(
                                        $validation['items']
                                    );

                                $items = $amounts['items'];
                                $quantity = $amounts['quantity'];
                            } else {
                                $amounts = $amountCalculator->calculate(
                                    $validation['quantity'],
                                    $validation['unitPrice'],
                                    $validation['currency']
                                );

                                $quantity = $amounts['quantity'];

                                $items = [
                                    [
                                        'ticketTypeId' =>
                                            $selection['ticketTypeId'],
                                        'quantity' => $quantity,
                                        'unitPrice' =>
                                            $amounts['unitPrice'],
                                        'subtotal' =>
                                            $amounts['subtotal'],
                                    ],
                                ];
                            }

                            $now = new DateTimeImmutable();
                            $timestamp = new Timestamp($now);

                            $buyerName = is_array($profile)
                                ? ($profile['name'] ?? '')
                                : '';

                            $buyerEmail = is_array($profile)
                                ? ($profile['email'] ?? '')
                                : '';

                            $order = [
                                'userId' => $uid,
                                'eventId' => $eventId,
                                'sellerId' => $sellerId,
                                'channel' => 'online',
                                'items' => $items,
                                'idempotencyKey' => $idempotencyKey,
                                'reservationId' => $reservationId,
                                'quantity' => $quantity,
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

                            // Mantener los campos originales
                            // de pedidos tradicionales.
                            if (!$isMultiple) {
                                $order['ticketTypeId'] =
                                    $selection['ticketTypeId'];

                                $order['unitPrice'] =
                                    $amounts['unitPrice'];
                            }

                            // Crear un solo pedido y vincular
                            // su reserva de manera atomica.
                            // No reservar inventario otra vez.
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

                    break;
                } catch (AbortedException $exception) {
                    if (
                        $attempt === self::MAX_TRANSACTION_ATTEMPTS
                    ) {
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

                    // Reintentar una transaccion
                    // cuando Firestore detecta conflicto.
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
     * Validar una reserva tradicional o multiple.
     */
    private function validateReservation(
        array $selection,
        array $reservation,
        string $userId,
        string $eventId,
        OrderReservationValidator $reservationValidator,
        bool $isMultiple
    ): array {
        if ($isMultiple) {
            return (new MultiOrderReservationValidator())
                ->validate(
                    $selection,
                    $reservation,
                    $userId,
                    $eventId
                );
        }

        return $reservationValidator->validate(
            $selection,
            $reservation,
            $userId,
            $eventId
        );
    }

    /**
     * Comprobar que la misma clave de idempotencia
     * corresponda a la misma seleccion.
     */
    private function orderMatchesSelection(
        array $order,
        array $selectedItems,
        bool $isMultiple
    ): bool {
        if (!$isMultiple) {
            return (
                isset($order['ticketTypeId']) &&
                $order['ticketTypeId'] ===
                    $selectedItems[0]['ticketTypeId'] &&
                ($order['quantity'] ?? null) ===
                    $selectedItems[0]['quantity']
            );
        }

        // No confundir pedidos tradicionales
        // con pedidos de formato multiple.
        if (
            array_key_exists('ticketTypeId', $order) ||
            !array_key_exists('items', $order)
        ) {
            return false;
        }

        try {
            $orderItems = (new ReservationItems())
                ->fromReservation($order);

            return $orderItems === $selectedItems;
        } catch (InvalidArgumentException $exception) {
            return false;
        }
    }

    /**
     * Preparar el resumen del pedido.
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
            'expiresAt' =>
                $expiresAt instanceof DateTimeInterface
                    ? $expiresAt->format(DateTimeInterface::ATOM)
                    : null,
        ];
    }
}
