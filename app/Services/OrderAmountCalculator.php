<?php

namespace App\Services;

use InvalidArgumentException;

class OrderAmountCalculator
{
    private const MAX_TICKETS = 10;

    private const MAX_UNIT_PRICE = 10000000;

    /**
     * Calcular importes de una categoria.
     * Todos los importes se manejan en centavos.
     */
    public function calculate(
        int $quantity,
        int $unitPrice,
        string $currency
    ): array {
        if (
            $quantity < 1 ||
            $quantity > self::MAX_TICKETS
        ) {
            throw new InvalidArgumentException(
                'La cantidad de boletos no es valida.'
            );
        }

        if (
            $unitPrice < 0 ||
            $unitPrice > self::MAX_UNIT_PRICE
        ) {
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
        $fees = $this->calculateFees($subtotal);

        return [
            'quantity' => $quantity,
            'unitPrice' => $unitPrice,
            'currency' => $currency,
            'subtotal' => $subtotal,
            'fees' => $fees,
            'total' => $subtotal + $fees,
        ];
    }

    /**
     * Calcular importes de varias categorias.
     *
     * Los precios deben provenir de una reserva validada
     * contra los datos del servidor.
     */
    public function calculateItems(array $items): array
    {
        if (
            !array_is_list($items) ||
            count($items) < 1 ||
            count($items) > self::MAX_TICKETS
        ) {
            throw new InvalidArgumentException(
                'La lista de boletos no es valida.'
            );
        }

        $orderItems = [];
        $usedTypes = [];
        $totalQuantity = 0;
        $subtotal = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException(
                    'Uno de los boletos no es valido.'
                );
            }

            $typeId = $item['ticketTypeId'] ?? null;
            $quantity = $item['quantity'] ?? null;
            $unitPrice = $item['unitPrice'] ?? null;
            $currency = $item['currency'] ?? null;

            if (
                !is_string($typeId) ||
                preg_match(
                    '/^[A-Za-z0-9_-]{1,128}$/D',
                    $typeId
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    'El tipo de boleto no es valido.'
                );
            }

            if (isset($usedTypes[$typeId])) {
                throw new InvalidArgumentException(
                    'El pedido contiene tipos de boleto repetidos.'
                );
            }

            if (
                !is_int($quantity) ||
                !is_int($unitPrice) ||
                !is_string($currency)
            ) {
                throw new InvalidArgumentException(
                    'Los datos del boleto no son validos.'
                );
            }

            // Reutilizar la validacion existente.
            $amount = $this->calculate(
                $quantity,
                $unitPrice,
                $currency
            );

            $usedTypes[$typeId] = true;
            $totalQuantity += $quantity;
            $subtotal += $amount['subtotal'];

            if ($totalQuantity > self::MAX_TICKETS) {
                throw new InvalidArgumentException(
                    'No puedes comprar mas de 10 boletos.'
                );
            }

            $orderItems[] = [
                'ticketTypeId' => $typeId,
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'subtotal' => $amount['subtotal'],
            ];
        }

        // Normalizar el orden para comparar pedidos.
        usort(
            $orderItems,
            static fn (array $first, array $second): int =>
                strcmp(
                    $first['ticketTypeId'],
                    $second['ticketTypeId']
                )
        );

        // Calcular la comision una sola vez sobre
        // el subtotal completo de la compra.
        $fees = $this->calculateFees($subtotal);

        return [
            'items' => $orderItems,
            'quantity' => $totalQuantity,
            'currency' => 'MXN',
            'subtotal' => $subtotal,
            'fees' => $fees,
            'total' => $subtotal + $fees,
        ];
    }

    /**
     * Calcular la comision sin utilizar decimales.
     */
    private function calculateFees(int $subtotal): int
    {
        $basisPoints = $this->serviceFeeBasisPoints();

        return intdiv(
            $subtotal * $basisPoints + 5000,
            10000
        );
    }

    /**
     * Leer y validar el porcentaje configurado.
     * Se aceptan porcentajes entre 0 y 100,
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
