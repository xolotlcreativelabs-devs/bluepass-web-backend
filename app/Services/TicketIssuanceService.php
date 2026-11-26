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

class TicketIssuanceService
{
    private const MAX_TRANSACTION_ATTEMPTS = 5;

    private PurchaseEmailOutboxService $emailOutbox;

    public function __construct(
        private FirestoreClient $firestore,
        private TicketIssuanceValidator $validator,
        private TicketIdGenerator $idGenerator,
        private TicketInventorySettlement $settlement,
        private TicketQrToken $qrToken,
        private PurchaseNotificationService $notificationService,
        ?PurchaseEmailOutboxService $emailOutbox = null
    ) {
        // Mantener compatibilidad con las pruebas existentes.
        $this->emailOutbox = $emailOutbox
            ?? new PurchaseEmailOutboxService($firestore);
    }

    /**
     * Emitir boletos despues de verificar el pago.
     *
     * Este metodo solo debe ser invocado por un proceso
     * interno que haya comprobado el pago.
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
            !is_string($paymentReference) ||
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

        // Identificar el formato del pedido.
        // Esta lectura no modifica ningun documento.
        $orderSnapshot = $orderDocument->snapshot();

        if (!$orderSnapshot->exists()) {
            throw new RuntimeException(
                'El pedido no existe.'
            );
        }

        $order = $orderSnapshot->data();

        if (!is_array($order)) {
            throw new RuntimeException(
                'Los datos del pedido son invalidos.'
            );
        }

        $format = $this->detectOrderFormat($order);

        if ($format === 'multiple') {
            // Reutilizar los mismos componentes para
            // mantener inventario, QR y notificaciones.
            $multipleService = new MultiTicketIssuanceService(
                $this->firestore,
                new MultiTicketIssuanceValidator(),
                $this->idGenerator,
                $this->settlement,
                $this->qrToken,
                $this->notificationService,
                $this->emailOutbox
            );

            return $multipleService->issueForVerifiedPayment(
                $orderId,
                $paymentReference
            );
        }

        // Mantener el procesamiento tradicional.
        $paymentReferenceId = hash(
            'sha256',
            $paymentReference
        );

        $paymentDocument = $this->firestore
            ->collection('paymentReferences')
            ->document($paymentReferenceId);

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
                        'Conflicto de concurrencia al emitir boletos BluePass',
                        [
                            'orderId' => $orderId,
                            'attempts' => $attempt,
                            'exception' => get_class($exception),
                        ]
                    );

                    throw new RuntimeException(
                        'No fue posible completar la emision por un conflicto temporal. Intenta nuevamente.',
                        0,
                        $exception
                    );
                }

                // Reintentar los conflictos temporales.
                $baseDelay = 150000 * (2 ** ($attempt - 1));

                $randomDelay = random_int(
                    0,
                    150000
                );

                usleep(
                    $baseDelay + $randomDelay
                );
            }
        }

        throw new RuntimeException(
            'No fue posible completar la emision.'
        );
    }

    /**
     * Identificar el formato de un pedido.
     *
     * Los pedidos tradicionales pueden incluir items,
     * por lo que ese campo no basta para identificarlos.
     */
    private function detectOrderFormat(array $order): string
    {
        $hasTraditionalType = array_key_exists(
            'ticketTypeId',
            $order
        );

        $hasItems = array_key_exists(
            'items',
            $order
        );

        if ($hasTraditionalType) {
            $ticketTypeId = $order['ticketTypeId'];

            if (!$this->validId($ticketTypeId)) {
                throw new RuntimeException(
                    'El pedido tiene un tipo de boleto invalido.'
                );
            }

            // Los pedidos tradicionales nuevos tambien
            // guardan un arreglo con su unica categoria.
            if ($hasItems) {
                $items = $order['items'];

                if (
                    !is_array($items) ||
                    count($items) !== 1 ||
                    !is_array($items[0]) ||
                    ($items[0]['ticketTypeId'] ?? null) !== $ticketTypeId ||
                    ($items[0]['quantity'] ?? null) !==
                        ($order['quantity'] ?? null)
                ) {
                    throw new RuntimeException(
                        'El pedido contiene formatos de boletos inconsistentes.'
                    );
                }
            }

            return 'traditional';
        }

        // Sin ticketTypeId, la seleccion debe
        // tener el formato de categorias multiples.
        if (
            !$hasItems ||
            !is_array($order['items']) ||
            $order['items'] === []
        ) {
            throw new RuntimeException(
                'El pedido no contiene categorias validas.'
            );
        }

        // El validador multiple revisara los datos
        // completos dentro de la transaccion.
        return 'multiple';
    }

    /**
     * Procesar una confirmacion tradicional
     * dentro de una transaccion.
     */
    private function processTransaction(
        Transaction $transaction,
        $orderDocument,
        $paymentDocument,
        string $orderId,
        string $paymentReference
    ): array {
        // Leer los documentos antes de escribir.
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
                'Los datos del pedido son invalidos.'
            );
        }

        // Volver a comprobar el formato con los datos
        // leidos dentro de la transaccion.
        if ($this->detectOrderFormat($order) !== 'traditional') {
            throw new RuntimeException(
                'El formato del pedido cambio durante la emision.'
            );
        }

        // Verificar que la referencia no pertenezca
        // a otro pedido.
        if ($paymentSnapshot->exists()) {
            $paymentData = $paymentSnapshot->data();

            if (
                !is_array($paymentData) ||
                ($paymentData['orderId'] ?? null) !== $orderId ||
                ($paymentData['paymentReference'] ?? null)
                    !== $paymentReference
            ) {
                throw new RuntimeException(
                    'La referencia de pago ya pertenece a otro pedido.'
                );
            }
        }

        // Una confirmacion repetida no debe
        // generar boletos ni ventas duplicadas.
        if (($order['status'] ?? null) === 'paid') {
            $ticketIds = $order['issuedTicketIds'] ?? null;

            if (
                !is_array($ticketIds) ||
                $ticketIds === [] ||
                ($order['paymentReference'] ?? null)
                    !== $paymentReference
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
        $ticketTypeId = $order['ticketTypeId'] ?? null;

        if (
            !$this->validId($eventId) ||
            !$this->validId($reservationId) ||
            !$this->validId($ticketTypeId)
        ) {
            throw new RuntimeException(
                'El pedido tiene referencias invalidas.'
            );
        }

        $eventDocument = $this->firestore
            ->collection('events')
            ->document($eventId);

        $reservationDocument = $eventDocument
            ->collection('reservations')
            ->document($reservationId);

        $inventoryDocument = $eventDocument
            ->collection('ticketTypes')
            ->document($ticketTypeId);

        $reservationSnapshot = $transaction->snapshot(
            $reservationDocument
        );

        $inventorySnapshot = $transaction->snapshot(
            $inventoryDocument
        );

        if (
            !$reservationSnapshot->exists() ||
            !$inventorySnapshot->exists()
        ) {
            throw new RuntimeException(
                'No se encontro la reserva o el inventario.'
            );
        }

        $reservation = $reservationSnapshot->data();
        $inventory = $inventorySnapshot->data();

        if (
            !is_array($reservation) ||
            !is_array($inventory)
        ) {
            throw new RuntimeException(
                'La reserva o el inventario son invalidos.'
            );
        }

        $now = new DateTimeImmutable();

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

        $newInventory = $this->settlement->calculate(
            $inventory,
            $quantity
        );

        $ticketIds = $this->idGenerator
            ->generateForOrder(
                $orderId,
                $quantity
            );

        $ticketDocuments = [];

        // Consultar los boletos antes de escribir.
        foreach ($ticketIds as $position => $ticketId) {
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
                'position' => $position + 1,
            ];
        }

        // Preparar la informacion del comprador.
        $buyer = $order['buyer'] ?? null;

        $buyerName = '';
        $buyerEmail = '';

        if (is_array($buyer)) {
            $buyerName = is_string($buyer['name'] ?? null)
                ? trim($buyer['name'])
                : '';

            $buyerEmail = is_string($buyer['email'] ?? null)
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

        // Registrar el uso exclusivo de la referencia.
        $transaction->create(
            $paymentDocument,
            [
                'orderId' => $orderId,
                'paymentReference' => $paymentReference,
                'createdAt' => $timestamp,
            ]
        );

        // Convertir los boletos reservados en vendidos.
        $transaction->update(
            $inventoryDocument,
            [
                [
                    'path' => 'sold',
                    'value' => $newInventory['sold'],
                ],
                [
                    'path' => 'reserved',
                    'value' => $newInventory['reserved'],
                ],
                [
                    'path' => 'updatedAt',
                    'value' => $timestamp,
                ],
            ]
        );

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

        // Marcar el pedido como pagado.
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

        // Emitir una entrada por cada boleto.
        foreach ($ticketDocuments as $ticketData) {
            $token = $this->qrToken->generate();

            $transaction->create(
                $ticketData['document'],
                [
                    'orderId' => $orderId,
                    'eventId' => $eventId,
                    'ticketTypeId' => $ticketTypeId,
                    'userId' => $validation['userId'],
                    'position' => $ticketData['position'],
                    'status' => 'valid',
                    'qrTokenHash' => $this->qrToken
                        ->hash($token),
                    'qrTokenEncrypted' => Crypt::encryptString(
                        $token
                    ),
                    'issuedAt' => $timestamp,
                    'usedAt' => null,
                    'updatedAt' => $timestamp,
                ]
            );
        }

        // Registrar la notificacion.
        $this->notificationService->createConfirmedPurchase(
            $transaction,
            $orderId,
            $validation['userId'],
            $ticketIds,
            $timestamp
        );

        // Registrar el correo para un envio posterior.
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
     * Validar identificadores de Firestore.
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
