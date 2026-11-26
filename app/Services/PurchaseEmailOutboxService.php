<?php

namespace App\Services;

use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use InvalidArgumentException;

class PurchaseEmailOutboxService
{
    public function __construct(
        private FirestoreClient $firestore
    ) {
    }

    /**
     * Registrar un correo pendiente dentro de la transaccion.
     *
     * Este metodo no envia correos.
     */
    public function createPending(
        Transaction $transaction,
        string $orderId,
        string $userId,
        string $buyerName,
        string $buyerEmail,
        array $ticketIds,
        Timestamp $timestamp
    ): string {
        if (!$this->validId($orderId)) {
            throw new InvalidArgumentException(
                'El identificador del pedido no es valido.'
            );
        }

        if (!$this->validId($userId)) {
            throw new InvalidArgumentException(
                'El identificador del comprador no es valido.'
            );
        }

        $buyerName = trim($buyerName);
        $buyerEmail = trim($buyerEmail);

        if (
            $buyerEmail === '' ||
            strlen($buyerEmail) > 254 ||
            filter_var(
                $buyerEmail,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            throw new InvalidArgumentException(
                'El correo del comprador no es valido.'
            );
        }

        if (mb_strlen($buyerName) > 150) {
            throw new InvalidArgumentException(
                'El nombre del comprador no es valido.'
            );
        }

        if ($ticketIds === []) {
            throw new InvalidArgumentException(
                'El pedido debe tener boletos emitidos.'
            );
        }

        foreach ($ticketIds as $ticketId) {
            if (!$this->validId($ticketId)) {
                throw new InvalidArgumentException(
                    'El pedido contiene un boleto no valido.'
                );
            }
        }

        if (
            count($ticketIds) !==
            count(array_unique($ticketIds))
        ) {
            throw new InvalidArgumentException(
                'El pedido contiene boletos duplicados.'
            );
        }

        // Un registro por pedido y tipo de correo.
        $emailId = hash(
            'sha256',
            'purchase_confirmation:' . $orderId
        );

        $document = $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document($emailId);

        $transaction->create(
            $document,
            [
                'orderId' => $orderId,
                'userId' => $userId,
                'type' => 'purchase_confirmation',
                'recipientName' => $buyerName,
                'recipientEmail' => $buyerEmail,
                'ticketCount' => count($ticketIds),
                'status' => 'pending',
                'attempts' => 0,
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
                'processingStartedAt' => null,
                'acceptedAt' => null,
                'lastErrorCode' => null,
            ]
        );

        return $emailId;
    }

    /**
     * Validar identificadores utilizados en Firestore.
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
