<?php

namespace App\Services;

use InvalidArgumentException;

class TicketIdGenerator
{
    /**
     * Generar un identificador estable para cada boleto.
     */
    public function generate(
        string $orderId,
        int $position
    ): string {
        if (
            !preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $orderId
            )
        ) {
            throw new InvalidArgumentException(
                'El identificador del pedido no es valido.'
            );
        }

        if ($position < 1 || $position > 10) {
            throw new InvalidArgumentException(
                'La posicion del boleto no es valida.'
            );
        }

        $value = 'bluepass:ticket:v1:'
            . $orderId
            . ':'
            . $position;

        return 'ticket_' . hash('sha256', $value);
    }

    /**
     * Generar los identificadores de todos los boletos.
     */
    public function generateForOrder(
        string $orderId,
        int $quantity
    ): array {
        if ($quantity < 1 || $quantity > 10) {
            throw new InvalidArgumentException(
                'La cantidad de boletos no es valida.'
            );
        }

        $ticketIds = [];

        for ($position = 1; $position <= $quantity; $position++) {
            $ticketIds[] = $this->generate(
                $orderId,
                $position
            );
        }

        return $ticketIds;
    }
}
