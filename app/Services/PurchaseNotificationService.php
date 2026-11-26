<?php

namespace App\Services;

use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use InvalidArgumentException;

class PurchaseNotificationService
{
    public function __construct(
        private FirestoreClient $firestore
    ) {
    }

    /**
     * Registrar una notificacion dentro de la transaccion de pago.
     */
    public function createConfirmedPurchase(
        Transaction $transaction,
        string $orderId,
        string $userId,
        array $ticketIds,
        Timestamp $timestamp
    ): void {
        if (
            !$this->validId($orderId) ||
            !$this->validId($userId) ||
            $ticketIds === []
        ) {
            throw new InvalidArgumentException(
                'Los datos de la notificacion no son validos.'
            );
        }

        foreach ($ticketIds as $ticketId) {
            if (!$this->validId($ticketId)) {
                throw new InvalidArgumentException(
                    'La lista de boletos no es valida.'
                );
            }
        }

        // El mismo pedido siempre produce el mismo identificador.
        $notificationId = hash(
            'sha256',
            'purchase_confirmed:' . $orderId
        );

        $notificationDocument = $this->firestore
            ->collection('notifications')
            ->document($notificationId);

        $notification = [
            'userId' => $userId,
            'orderId' => $orderId,
            'type' => 'purchase_confirmed',
            'title' => 'Compra confirmada',
            'message' => 'Tu compra fue confirmada y tus boletos ya estan disponibles en Mis boletos.',
            'status' => 'unread',
            'channel' => 'in_app',
            'ticketCount' => count($ticketIds),
            'createdAt' => $timestamp,
            'readAt' => null,
        ];

        // La notificacion se guarda con la misma transaccion.
        $transaction->create(
            $notificationDocument,
            $notification
        );
    }

    /**
     * Validar los identificadores utilizados por BluePass.
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
