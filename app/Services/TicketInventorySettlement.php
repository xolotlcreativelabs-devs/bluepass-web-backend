<?php

namespace App\Services;

use InvalidArgumentException;

class TicketInventorySettlement
{
    /**
     * Calcular el inventario despues de confirmar una compra.
     *
     * Este servicio no modifica Firestore.
     */
    public function calculate(
        array $inventory,
        int $quantity
    ): array {
        $capacity = $inventory['capacity'] ?? null;
        $sold = $inventory['sold'] ?? null;
        $reserved = $inventory['reserved'] ?? null;

        if (
            !is_int($capacity) ||
            !is_int($sold) ||
            !is_int($reserved) ||
            $capacity < 0 ||
            $sold < 0 ||
            $reserved < 0 ||
            $sold > $capacity ||
            $reserved > $capacity - $sold
        ) {
            throw new InvalidArgumentException(
                'El inventario es inconsistente.'
            );
        }

        if ($quantity < 1 || $quantity > 10) {
            throw new InvalidArgumentException(
                'La cantidad de boletos no es valida.'
            );
        }

        if ($reserved < $quantity) {
            throw new InvalidArgumentException(
                'No existen suficientes boletos reservados.'
            );
        }

        $newSold = $sold + $quantity;
        $newReserved = $reserved - $quantity;

        return [
            'capacity' => $capacity,
            'sold' => $newSold,
            'reserved' => $newReserved,
            'available' => $capacity - $newSold - $newReserved,
        ];
    }
}
