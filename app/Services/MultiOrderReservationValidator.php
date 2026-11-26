<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use InvalidArgumentException;

class MultiOrderReservationValidator
{
    /**
     * Comprobar que un pedido mixto coincide con su reserva.
     */
    public function validate(
        array $selection,
        array $reservation,
        string $userId,
        string $eventId,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= new DateTimeImmutable();

        // La reserva debe pertenecer al comprador.
        if (
            $userId === '' ||
            ($reservation['userId'] ?? null) !== $userId
        ) {
            return $this->failure(
                403,
                'No tienes permisos para utilizar esta reserva.'
            );
        }

        if (($reservation['eventId'] ?? null) !== $eventId) {
            return $this->failure(
                409,
                'La reserva no corresponde al evento.'
            );
        }

        // Comprobar que la reserva siga activa.
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

        if (!array_key_exists('items', $reservation)) {
            return $this->failure(
                409,
                'La reserva no contiene una seleccion multiple.'
            );
        }

        // Validar categorias, cantidades y total de la reserva.
        try {
            $reservationItems = (new ReservationItems())
                ->fromReservation($reservation);
        } catch (InvalidArgumentException $exception) {
            return $this->failure(
                409,
                'Los boletos de la reserva no son validos.'
            );
        }

        // Validar la seleccion enviada por el comprador.
        try {
            $validatedSelection =
                (new MultiTicketSelectionValidator())
                    ->validate([
                        'items' => $selection['items'] ?? null,
                    ]);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return $this->failure(
                409,
                'Los boletos seleccionados no son validos.'
            );
        }

        if ($validatedSelection['items'] !== $reservationItems) {
            return $this->failure(
                409,
                'La seleccion no coincide con la reserva.'
            );
        }

        if (($reservation['currency'] ?? null) !== 'MXN') {
            return $this->failure(
                409,
                'La moneda de la reserva no es valida.'
            );
        }

        // Recuperar los precios registrados en la reserva.
        // Nunca utilizar precios proporcionados por el comprador.
        $pricesByType = [];

        foreach ($reservation['items'] as $item) {
            if (!is_array($item)) {
                return $this->failure(
                    409,
                    'Los datos de la reserva no son validos.'
                );
            }

            $typeId = $item['ticketTypeId'] ?? null;
            $price = $item['unitPrice'] ?? null;
            $currency = $item['currency'] ?? null;

            if (
                !is_string($typeId) ||
                !is_int($price) ||
                $price < 0 ||
                $price > 10000000 ||
                $currency !== 'MXN'
            ) {
                return $this->failure(
                    409,
                    'El precio o la moneda de la reserva no es valido.'
                );
            }

            $pricesByType[$typeId] = $price;
        }

        $items = [];

        foreach ($reservationItems as $item) {
            $typeId = $item['ticketTypeId'];

            if (!array_key_exists($typeId, $pricesByType)) {
                return $this->failure(
                    409,
                    'No se encontro el precio del boleto.'
                );
            }

            $items[] = [
                'ticketTypeId' => $typeId,
                'quantity' => $item['quantity'],
                'unitPrice' => $pricesByType[$typeId],
                'currency' => 'MXN',
            ];
        }

        return [
            'valid' => true,
            'code' => 200,
            'message' => 'Reserva multiple validada correctamente.',
            'items' => $items,
            'quantity' => $validatedSelection['quantity'],
            'currency' => 'MXN',
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Convertir una fecha almacenada en Firestore.
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
     * Preparar un resultado rechazado.
     */
    private function failure(
        int $code,
        string $message
    ): array {
        return [
            'valid' => false,
            'code' => $code,
            'message' => $message,
        ];
    }
}
