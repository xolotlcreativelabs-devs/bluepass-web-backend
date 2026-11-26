<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;

class TicketIssuanceValidator
{
    /**
     * Validar que un pedido y su reserva permitan continuar
     * con el proceso interno de confirmacion de compra.
     *
     * Esta validacion no comprueba por si sola el pago.
     */
    public function validate(
        array $order,
        array $reservation,
        string $orderId,
        DateTimeImmutable $now
    ): array {
        if (($order['status'] ?? null) !== 'pending') {
            return $this->reject(
                'El pedido no esta pendiente.'
            );
        }

        if (($reservation['status'] ?? null) !== 'active') {
            return $this->reject(
                'La reserva no esta activa.'
            );
        }

        $orderUserId = $order['userId'] ?? null;
        $orderEventId = $order['eventId'] ?? null;
        $orderTicketTypeId = $order['ticketTypeId'] ?? null;
        $orderReservationId = $order['reservationId'] ?? null;
        $quantity = $order['quantity'] ?? null;

        if (
            !$this->validId($orderId) ||
            !$this->validId($orderUserId) ||
            !$this->validId($orderEventId) ||
            !$this->validId($orderTicketTypeId) ||
            !$this->validId($orderReservationId) ||
            !is_int($quantity) ||
            $quantity < 1 ||
            $quantity > 10
        ) {
            return $this->reject(
                'El pedido contiene datos invalidos.'
            );
        }

        if (
            ($reservation['orderId'] ?? null) !== $orderId ||
            ($reservation['userId'] ?? null) !== $orderUserId ||
            ($reservation['eventId'] ?? null) !== $orderEventId ||
            ($reservation['ticketTypeId'] ?? null) !== $orderTicketTypeId ||
            ($reservation['quantity'] ?? null) !== $quantity
        ) {
            return $this->reject(
                'El pedido no coincide con la reserva.'
            );
        }

        $expiresAt = $this->readTimestamp(
            $reservation['expiresAt'] ?? null
        );

        if ($expiresAt === null) {
            return $this->reject(
                'La reserva no tiene un vencimiento valido.'
            );
        }

        if ($now >= $expiresAt) {
            return $this->reject(
                'La reserva ya vencio.'
            );
        }

        return [
            'valid' => true,
            'message' => 'El pedido y la reserva son validos.',
            'quantity' => $quantity,
            'userId' => $orderUserId,
            'eventId' => $orderEventId,
            'ticketTypeId' => $orderTicketTypeId,
            'reservationId' => $orderReservationId,
        ];
    }

    /**
     * Responder cuando los datos no son validos.
     */
    private function reject(string $message): array
    {
        return [
            'valid' => false,
            'message' => $message,
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

    /**
     * Convertir fechas almacenadas en Firestore.
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
