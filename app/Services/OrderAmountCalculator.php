<?php

namespace App\Services;

use InvalidArgumentException;

class OrderAmountCalculator
{
    /**
     * Calcular los importes utilizando datos validados.
     * Todos los importes se manejan en centavos.
     */
    public function calculate(
        int $quantity,
        int $unitPrice,
        string $currency
    ): array {
        if ($quantity < 1 || $quantity > 10) {
            throw new InvalidArgumentException(
                'La cantidad de boletos no es valida.'
            );
        }

        if ($unitPrice < 0 || $unitPrice > 10000000) {
            throw new InvalidArgumentException(
                'El precio unitario no es valido.'
            );
        }

        if ($currency !== 'MXN') {
            throw new InvalidArgumentException(
                'La moneda del pedido no es valida.'
            );
        }

        $subtotal = $quantity * $unitPrice;

        $basisPoints = $this->serviceFeeBasisPoints();

        // Calcular sin numeros flotantes para evitar errores
        // de precision en los importes monetarios.
        // Un punto base representa 0.01 %.
        $fees = intdiv(
            $subtotal * $basisPoints + 5000,
            10000
        );

        $total = $subtotal + $fees;

        return [
            'quantity' => $quantity,
            'unitPrice' => $unitPrice,
            'currency' => $currency,
            'subtotal' => $subtotal,
            'fees' => $fees,
            'total' => $total,
        ];
    }

    /**
     * Leer y validar el porcentaje configurado.
     * Se aceptan porcentajes desde 0 hasta 100,
     * con un maximo de dos decimales.
     */
    private function serviceFeeBasisPoints(): int
    {
        $configured = config(
            'bluepass.service_fee_percent',
            '0'
        );

        if (
            !is_string($configured) &&
            !is_int($configured)
        ) {
            throw new InvalidArgumentException(
                'La comision de BluePass no es valida.'
            );
        }

        $percent = (string) $configured;

        if (
            !preg_match(
                '/^(?:0|[1-9][0-9]?|100)(?:\.[0-9]{1,2})?$/D',
                $percent
            )
        ) {
            throw new InvalidArgumentException(
                'La comision de BluePass no es valida.'
            );
        }

        $parts = explode('.', $percent, 2);

        $whole = (int) $parts[0];

        $decimal = isset($parts[1])
            ? (int) str_pad($parts[1], 2, '0')
            : 0;

        $basisPoints = $whole * 100 + $decimal;

        if ($basisPoints > 10000) {
            throw new InvalidArgumentException(
                'La comision de BluePass no puede superar el 100 %.'
            );
        }

        return $basisPoints;
    }
}
