<?php

namespace App\Services;

use DateTimeImmutable;
use Google\Cloud\Core\Exception\AbortedException;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class MultiTicketIssuanceService
{
    private const MAX_TRANSACTION_ATTEMPTS = 5;

    private PurchaseEmailOutboxService $emailOutbox;

    public function __construct(
        private FirestoreClient $firestore,
        private MultiTicketIssuanceValidator $validator,
        private TicketIdGenerator $idGenerator,
        private TicketInventorySettlement $settlement,
        private TicketQrToken $qrToken,
        private PurchaseNotificationService $notificationService,
        ?PurchaseEmailOutboxService $emailOutbox = null
    ) {
        $this->emailOutbox = $emailOutbox
            ?? new PurchaseEmailOutboxService($firestore);
    }

    /**
     * Emitir boletos de varias categorias.
     *
     * Solo puede llamarse desde un proceso interno
     * que ya haya verificado el pago.
     */
    public function issueForVerifiedPayment(
        string $orderId,
        string $paymentReference
    ): array {
        if (!$this->validId($orderId)) {
            throw new InvalidArgumentException(
                'El identificador del pedido no es valido.'
            );
        }

        if (
            preg_match(
                '/^[A-Za-z0-9._-]{8,128}$/D',
                $paymentReference
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'La referencia del pago no es valida.'
            );
        }

        $orderDocument = $this->firestore
            ->collection('orders')
            ->document($orderId);

        $paymentDocument = $this->firestore
            ->collection('paymentReferences')
            ->document(
                hash('sha256', $paymentReference)
            );

        for (
            $attempt = 1;
            $attempt <= self::MAX_TRANSACTION_ATTEMPTS;
            $attempt++
        ) {
            try {
                return $this->firestore->runTransaction(
                    function (Transaction $transaction) use (
                        $orderDocument,
                        $paymentDocument,
                        $orderId,
                        $paymentReference
                    ): array {
                        return $this->processTransaction(
                            $transaction,
                            $orderDocument,
                            $paymentDocument,
                            $orderId,
                            $paymentReference
                        );
                    }
                );
            } catch (AbortedException $exception) {
                if (
                    $attempt ===
                    self::MAX_TRANSACTION_ATTEMPTS
                ) {
                    Log::warning(
                        'Conflicto al emitir boletos multiples',
                        [
                            'orderId' => $orderId,
                            'attempts' => $attempt,
                        ]
                    );

                    throw new RuntimeException(
                        'No fue posible completar la emision por un conflicto temporal.',
                        0,
                        $exception
                    );
                }

                $baseDelay = 150000 * (2 ** ($attempt - 1));

                usleep(
                    $baseDelay + random_int(0, 150000)
                );
            }
        }

        throw new RuntimeException(
            'No fue posible completar la emision.'
        );
    }

    /**
     * Procesar todas las categorias en una transaccion.
     */
    private function processTransaction(
        Transaction $transaction,
        $orderDocument,
        $paymentDocument,
        string $orderId,
        string $paymentReference
    ): array {
        // Consultar primero el pedido y la referencia.
        $orderSnapshot = $transaction->snapshot(
            $orderDocument
        );

        $paymentSnapshot = $transaction->snapshot(
            $paymentDocument
        );

        if (!$orderSnapshot->exists()) {
            throw new RuntimeException(
                'El pedido no existe.'
            );
        }

        $order = $orderSnapshot->data();

        if (!is_array($order)) {
            throw new RuntimeException(
                'El pedido contiene datos invalidos.'
            );
        }

        if ($paymentSnapshot->exists()) {
            $payment = $paymentSnapshot->data();

            if (
                !is_array($payment) ||
                ($payment['orderId'] ?? null) !== $orderId ||
                ($payment['paymentReference'] ?? null) !==
                    $paymentReference
            ) {
                throw new RuntimeException(
                    'La referencia de pago ya pertenece a otro pedido.'
                );
            }
        }

        // Una confirmacion repetida no debe emitir
        // boletos ni descontar inventario de nuevo.
        if (($order['status'] ?? null) === 'paid') {
            $ticketIds = $order['issuedTicketIds'] ?? null;

            if (
                !is_array($ticketIds) ||
                $ticketIds === [] ||
                ($order['paymentReference'] ?? null) !==
                    $paymentReference
            ) {
                throw new RuntimeException(
                    'El pedido ya fue procesado con otros datos.'
                );
            }

            if (!$paymentSnapshot->exists()) {
                throw new RuntimeException(
                    'El pedido pagado no tiene una referencia registrada.'
                );
            }

            return [
                'orderId' => $orderId,
                'status' => 'paid',
                'alreadyIssued' => true,
                'ticketIds' => $ticketIds,
            ];
        }

        if ($paymentSnapshot->exists()) {
            throw new RuntimeException(
                'La referencia de pago ya fue registrada.'
            );
        }

        $eventId = $order['eventId'] ?? null;
        $reservationId = $order['reservationId'] ?? null;

        if (
            !$this->validId($eventId) ||
            !$this->validId($reservationId)
        ) {
            throw new RuntimeException(
                'El pedido tiene referencias invalidas.'
            );
        }

        if (
            !isset($order['items']) ||
            !is_array($order['items']) ||
            $order['items'] === []
        ) {
            throw new RuntimeException(
                'El pedido no contiene categorias validas.'
            );
        }

        $eventDocument = $this->firestore
            ->collection('events')
            ->document($eventId);

        $reservationDocument = $eventDocument
            ->collection('reservations')
            ->document($reservationId);

        // Preparar los documentos de inventario.
        $inventoryDocuments = [];

        foreach ($order['items'] as $item) {
            $typeId = is_array($item)
                ? ($item['ticketTypeId'] ?? null)
                : null;

            if (
                !$this->validId($typeId) ||
                array_key_exists(
                    $typeId,
                    $inventoryDocuments
                )
            ) {
                throw new RuntimeException(
                    'El pedido contiene categorias invalidas o duplicadas.'
                );
            }

            $inventoryDocuments[$typeId] = $eventDocument
                ->collection('ticketTypes')
                ->document($typeId);
        }

        // Todas las lecturas antes de las escrituras.
        $reservationSnapshot = $transaction->snapshot(
            $reservationDocument
        );

        $inventorySnapshots = [];

        foreach (
            $inventoryDocuments as $typeId => $document
        ) {
            $inventorySnapshots[$typeId] =
                $transaction->snapshot($document);
        }

        if (!$reservationSnapshot->exists()) {
            throw new RuntimeException(
                'La reserva no existe.'
            );
        }

        $reservation = $reservationSnapshot->data();

        if (!is_array($reservation)) {
            throw new RuntimeException(
                'La reserva contiene datos invalidos.'
            );
        }

        $now = new DateTimeImmutable();

        // Comprobar cantidades, importes, categorias,
        // comprador y vencimiento de la reserva.
        $validation = $this->validator->validate(
            $order,
            $reservation,
            $orderId,
            $now
        );

        if (!$validation['valid']) {
            throw new RuntimeException(
                $validation['message']
            );
        }

        $quantity = $validation['quantity'];
        $validatedItems = $validation['items'];

        $newInventories = [];

        foreach ($validatedItems as $item) {
            $typeId = $item['ticketTypeId'];

            $snapshot = $inventorySnapshots[$typeId]
                ?? null;

            if (
                $snapshot === null ||
                !$snapshot->exists()
            ) {
                throw new RuntimeException(
                    'No se encontro el inventario de una categoria.'
                );
            }

            $inventory = $snapshot->data();

            if (!is_array($inventory)) {
                throw new RuntimeException(
                    'El inventario contiene datos invalidos.'
                );
            }

            // Comprobar y calcular cada inventario
            // sin realizar escrituras todavia.
            $newInventories[$typeId] =
                $this->settlement->calculate(
                    $inventory,
                    $item['quantity']
                );
        }

        // Generar los identificadores de los cinco
        // boletos de forma determinista.
        $ticketIds = $this->idGenerator
            ->generateForOrder(
                $orderId,
                $quantity
            );

        $ticketDocuments = [];
        $position = 0;

        foreach ($validatedItems as $item) {
            for ($i = 0; $i < $item['quantity']; $i++) {
                $ticketId = $ticketIds[$position];

                $document = $this->firestore
                    ->collection('tickets')
                    ->document($ticketId);

                $snapshot = $transaction->snapshot(
                    $document
                );

                if ($snapshot->exists()) {
                    throw new RuntimeException(
                        'Ya existe un boleto para este pedido.'
                    );
                }

                $ticketDocuments[] = [
                    'document' => $document,
                    'ticketTypeId' =>
                        $item['ticketTypeId'],
                    'position' => $position + 1,
                ];

                $position++;
            }
        }

        // Preparar los datos del comprador.
        $buyer = $order['buyer'] ?? null;

        $buyerName = '';
        $buyerEmail = '';

        if (is_array($buyer)) {
            $buyerName = is_string(
                $buyer['name'] ?? null
            )
                ? trim($buyer['name'])
                : '';

            $buyerEmail = is_string(
                $buyer['email'] ?? null
            )
                ? trim($buyer['email'])
                : '';
        }

        $canRegisterEmail =
            $buyerEmail !== '' &&
            strlen($buyerEmail) <= 254 &&
            filter_var(
                $buyerEmail,
                FILTER_VALIDATE_EMAIL
            ) !== false &&
            mb_strlen($buyerName) <= 150;

        $timestamp = new Timestamp($now);

        // Registrar el identificador del pago.
        $transaction->create(
            $paymentDocument,
            [
                'orderId' => $orderId,
                'paymentReference' => $paymentReference,
                'createdAt' => $timestamp,
            ]
        );

        // Convertir a vendidos los inventarios
        // de todas las categorias.
        foreach (
            $newInventories as $typeId => $inventory
        ) {
            $transaction->update(
                $inventoryDocuments[$typeId],
                [
                    [
                        'path' => 'sold',
                        'value' => $inventory['sold'],
                    ],
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

        // Confirmar la reserva.
        $transaction->update(
            $reservationDocument,
            [
                [
                    'path' => 'status',
                    'value' => 'confirmed',
                ],
                [
                    'path' => 'updatedAt',
                    'value' => $timestamp,
                ],
            ]
        );

        // Registrar el pedido como pagado.
        $transaction->update(
            $orderDocument,
            [
                [
                    'path' => 'status',
                    'value' => 'paid',
                ],
                [
                    'path' => 'paymentReference',
                    'value' => $paymentReference,
                ],
                [
                    'path' => 'paidAt',
                    'value' => $timestamp,
                ],
                [
                    'path' => 'issuedTicketIds',
                    'value' => $ticketIds,
                ],
                [
                    'path' => 'updatedAt',
                    'value' => $timestamp,
                ],
            ]
        );

        // Emitir un boleto individual por entrada.
        foreach ($ticketDocuments as $ticketData) {
            $token = $this->qrToken->generate();

            $transaction->create(
                $ticketData['document'],
                [
                    'orderId' => $orderId,
                    'eventId' => $eventId,
                    'ticketTypeId' =>
                        $ticketData['ticketTypeId'],
                    'userId' => $validation['userId'],
                    'position' =>
                        $ticketData['position'],
                    'status' => 'valid',
                    'qrTokenHash' => $this->qrToken
                        ->hash($token),
                    'qrTokenEncrypted' =>
                        Crypt::encryptString($token),
                    'issuedAt' => $timestamp,
                    'usedAt' => null,
                    'updatedAt' => $timestamp,
                ]
            );
        }

        // Registrar la notificacion sin duplicarla.
        $this->notificationService
            ->createConfirmedPurchase(
                $transaction,
                $orderId,
                $validation['userId'],
                $ticketIds,
                $timestamp
            );

        // Registrar el correo pendiente.
        // No enviarlo dentro de la transaccion.
        if ($canRegisterEmail) {
            $this->emailOutbox->createPending(
                $transaction,
                $orderId,
                $validation['userId'],
                $buyerName,
                $buyerEmail,
                $ticketIds,
                $timestamp
            );
        }

        return [
            'orderId' => $orderId,
            'status' => 'paid',
            'alreadyIssued' => false,
            'ticketIds' => $ticketIds,
        ];
    }

    /**
     * Validar identificadores de documentos.
     */
    private function validId(mixed $value): bool
    {
        return is_string($value) &&
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $value
            ) === 1;
    }
}
