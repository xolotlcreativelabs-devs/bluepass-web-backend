<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;

class OrderReservationValidator
{
    /**
     * Comprobar que una reserva corresponde a la seleccion del comprador.
     */
    public function validate(
        array $selection,
        array $reservation,
        string $userId,
        string $eventId,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= new DateTimeImmutable();

        if (
            $userId === '' ||
            ($reservation['userId'] ?? null) !== $userId
        ) {
            return $this->failure(
                403,
                'No tienes permisos para utilizar esta reserva.'
            );
        }

        if (
            ($reservation['eventId'] ?? null) !== $eventId ||
            ($reservation['ticketTypeId'] ?? null) !==
                ($selection['ticketTypeId'] ?? null)
        ) {
            return $this->failure(
                409,
                'La reserva no coincide con los boletos seleccionados.'
            );
        }

        $quantity = $reservation['quantity'] ?? null;

        if (
            !is_int($quantity) ||
            $quantity < 1 ||
            $quantity > 10 ||
            $quantity !== ($selection['quantity'] ?? null)
        ) {
            return $this->failure(
                409,
                'La cantidad seleccionada no coincide con la reserva.'
            );
        }

        if (($reservation['status'] ?? null) !== 'active') {
            return $this->failure(
                409,
                'La reserva ya no esta activa.'
            );
        }

        $expiresAt = $this->readTimestamp(
            $reservation['expiresAt'] ?? null
        );

        if ($expiresAt === null) {
            return $this->failure(
                409,
                'La reserva no tiene un vencimiento valido.'
            );
        }

        if ($now >= $expiresAt) {
            return $this->failure(
                409,
                'La reserva ha vencido.'
            );
        }

        $unitPrice = $reservation['unitPrice'] ?? null;

        if (!is_int($unitPrice) || $unitPrice < 0) {
            return $this->failure(
                409,
                'El precio registrado en la reserva no es valido.'
            );
        }

        if (($reservation['currency'] ?? null) !== 'MXN') {
            return $this->failure(
                409,
                'La moneda de la reserva no es valida.'
            );
        }

        return [
            'valid' => true,
            'code' => 200,
            'message' => 'Reserva validada correctamente.',
            'quantity' => $quantity,
            'unitPrice' => $unitPrice,
            'currency' => 'MXN',
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Convertir una fecha de Firestore a una fecha comparable.
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

    /**
     * Preparar el resultado de una validacion rechazada.
     */
    private function failure(int $code, string $message): array
    {
        return [
            'valid' => false,
            'code' => $code,
            'message' => $message,
        ];
    }
}
