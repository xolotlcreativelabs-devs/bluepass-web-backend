<?php

namespace App\Services;

use InvalidArgumentException;

class ReservationItems
{
    private const MAX_TICKETS = 10;

    /**
     * Obtener los tipos y cantidades de una reserva.
     *
     * Compatible con reservas tradicionales y multiples.
     */
    public function fromReservation(array $reservation): array
    {
        if (array_key_exists('items', $reservation)) {
            return $this->readMultipleItems($reservation);
        }

        return $this->readSingleItem($reservation);
    }

    /**
     * Interpretar una reserva tradicional.
     */
    private function readSingleItem(array $reservation): array
    {
        $typeId = $reservation['ticketTypeId'] ?? null;
        $quantity = $reservation['quantity'] ?? null;

        if (
            !$this->validId($typeId) ||
            !is_int($quantity) ||
            $quantity < 1 ||
            $quantity > self::MAX_TICKETS
        ) {
            throw new InvalidArgumentException(
                'La reserva tradicional contiene datos invalidos.'
            );
        }

        return [
            [
                'ticketTypeId' => $typeId,
                'quantity' => $quantity,
            ],
        ];
    }

    /**
     * Interpretar y comprobar una reserva multiple.
     */
    private function readMultipleItems(array $reservation): array
    {
        $items = $reservation['items'];
        $totalQuantity = $reservation['quantity'] ?? null;

        if (
            !is_array($items) ||
            !array_is_list($items) ||
            count($items) < 1 ||
            count($items) > self::MAX_TICKETS ||
            !is_int($totalQuantity) ||
            $totalQuantity < 1 ||
            $totalQuantity > self::MAX_TICKETS
        ) {
            throw new InvalidArgumentException(
                'La reserva multiple contiene datos invalidos.'
            );
        }

        $normalized = [];
        $usedTypes = [];
        $sum = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException(
                    'Uno de los elementos de la reserva es invalido.'
                );
            }

            $typeId = $item['ticketTypeId'] ?? null;
            $quantity = $item['quantity'] ?? null;

            if (
                !$this->validId($typeId) ||
                !is_int($quantity) ||
                $quantity < 1 ||
                $quantity > self::MAX_TICKETS
            ) {
                throw new InvalidArgumentException(
                    'Uno de los tipos o cantidades es invalido.'
                );
            }

            if (isset($usedTypes[$typeId])) {
                throw new InvalidArgumentException(
                    'La reserva contiene tipos de boleto repetidos.'
                );
            }

            $usedTypes[$typeId] = true;
            $sum += $quantity;

            if ($sum > self::MAX_TICKETS) {
                throw new InvalidArgumentException(
                    'La reserva supera el limite de boletos.'
                );
            }

            $normalized[] = [
                'ticketTypeId' => $typeId,
                'quantity' => $quantity,
            ];
        }

        if ($sum !== $totalQuantity) {
            throw new InvalidArgumentException(
                'La cantidad total no coincide con los boletos reservados.'
            );
        }

        // Ordenar las categorias de forma consistente.
        usort(
            $normalized,
            static fn (array $first, array $second): int =>
                strcmp(
                    $first['ticketTypeId'],
                    $second['ticketTypeId']
                )
        );

        return $normalized;
    }

    /**
     * Validar identificadores de tipos de boleto.
     */
    private function validId(mixed $id): bool
    {
        return is_string($id) &&
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $id
            ) === 1;
    }
}
