<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use InvalidArgumentException;

class MultiTicketIssuanceValidator
{
    private const MAX_TICKETS = 10;
    private const MAX_UNIT_PRICE = 10000000;

    /**
     * Validar un pedido multiple y su reserva.
     *
     * Esta validacion no confirma pagos.
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

        $userId = $order['userId'] ?? null;
        $eventId = $order['eventId'] ?? null;
        $reservationId = $order['reservationId'] ?? null;

        if (
            !$this->validId($orderId) ||
            !$this->validId($userId) ||
            !$this->validId($eventId) ||
            !$this->validId($reservationId)
        ) {
            return $this->reject(
                'El pedido contiene referencias invalidas.'
            );
        }

        if (
            ($reservation['orderId'] ?? null) !== $orderId ||
            ($reservation['userId'] ?? null) !== $userId ||
            ($reservation['eventId'] ?? null) !== $eventId
        ) {
            return $this->reject(
                'El pedido no coincide con la reserva.'
            );
        }

        $expiresAt = $this->readTimestamp(
            $reservation['expiresAt'] ?? null
        );

        if ($expiresAt === null || $now >= $expiresAt) {
            return $this->reject(
                'La reserva no tiene un vencimiento valido o ya vencio.'
            );
        }

        if (
            !array_key_exists('items', $order) ||
            !array_key_exists('items', $reservation)
        ) {
            return $this->reject(
                'El pedido y la reserva deben contener sus categorias.'
            );
        }

        try {
            $reader = new ReservationItems();

            $orderItems = $reader->fromReservation($order);
            $reservationItems = $reader->fromReservation(
                $reservation
            );
        } catch (InvalidArgumentException $exception) {
            return $this->reject(
                'Los boletos del pedido o la reserva son invalidos.'
            );
        }

        if ($orderItems !== $reservationItems) {
            return $this->reject(
                'Los boletos del pedido no coinciden con la reserva.'
            );
        }

        if (
            ($order['currency'] ?? null) !== 'MXN' ||
            ($reservation['currency'] ?? null) !== 'MXN'
        ) {
            return $this->reject(
                'La moneda del pedido o la reserva no es valida.'
            );
        }

        $reservationPrices = [];

        foreach ($reservation['items'] as $item) {
            if (!is_array($item)) {
                return $this->reject(
                    'Una categoria de la reserva es invalida.'
                );
            }

            $typeId = $item['ticketTypeId'] ?? null;
            $price = $item['unitPrice'] ?? null;

            if (
                !$this->validId($typeId) ||
                !is_int($price) ||
                $price < 0 ||
                $price > self::MAX_UNIT_PRICE ||
                ($item['currency'] ?? null) !== 'MXN'
            ) {
                return $this->reject(
                    'Los precios de la reserva no son validos.'
                );
            }

            $reservationPrices[$typeId] = $price;
        }

        $orderPrices = [];

        foreach ($order['items'] as $item) {
            if (!is_array($item)) {
                return $this->reject(
                    'Una categoria del pedido es invalida.'
                );
            }

            $typeId = $item['ticketTypeId'] ?? null;
            $price = $item['unitPrice'] ?? null;
            $quantity = $item['quantity'] ?? null;
            $subtotal = $item['subtotal'] ?? null;

            if (
                !$this->validId($typeId) ||
                !is_int($price) ||
                !is_int($quantity) ||
                !is_int($subtotal) ||
                $price < 0 ||
                $price > self::MAX_UNIT_PRICE ||
                $subtotal !== $quantity * $price
            ) {
                return $this->reject(
                    'Los importes del pedido no son validos.'
                );
            }

            $orderPrices[$typeId] = [
                'unitPrice' => $price,
                'subtotal' => $subtotal,
            ];
        }

        $totalQuantity = 0;
        $calculatedSubtotal = 0;
        $validatedItems = [];

        foreach ($orderItems as $item) {
            $typeId = $item['ticketTypeId'];
            $quantity = $item['quantity'];

            if (
                !array_key_exists($typeId, $reservationPrices) ||
                !array_key_exists($typeId, $orderPrices)
            ) {
                return $this->reject(
                    'Faltan precios de una categoria.'
                );
            }

            $unitPrice = $reservationPrices[$typeId];

            if (
                $orderPrices[$typeId]['unitPrice'] !==
                $unitPrice
            ) {
                return $this->reject(
                    'El precio del pedido no coincide con la reserva.'
                );
            }

            $totalQuantity += $quantity;
            $calculatedSubtotal += $quantity * $unitPrice;

            $validatedItems[] = [
                'ticketTypeId' => $typeId,
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
            ];
        }

        if (
            $totalQuantity < 1 ||
            $totalQuantity > self::MAX_TICKETS ||
            ($order['quantity'] ?? null) !== $totalQuantity ||
            ($reservation['quantity'] ?? null) !== $totalQuantity
        ) {
            return $this->reject(
                'La cantidad total de boletos es invalida.'
            );
        }

        $fees = $order['fees'] ?? null;
        $total = $order['total'] ?? null;

        if (
            ($order['subtotal'] ?? null) !== $calculatedSubtotal ||
            !is_int($fees) ||
            $fees < 0 ||
            !is_int($total) ||
            $total !== $calculatedSubtotal + $fees
        ) {
            return $this->reject(
                'El resumen de importes del pedido es inconsistente.'
            );
        }

        return [
            'valid' => true,
            'message' => 'El pedido multiple y la reserva son validos.',
            'userId' => $userId,
            'eventId' => $eventId,
            'reservationId' => $reservationId,
            'quantity' => $totalQuantity,
            'items' => $validatedItems,
            'currency' => 'MXN',
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
     * Convertir una fecha de Firestore.
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
    private function reject(string $message): array
    {
        return [
            'valid' => false,
            'message' => $message,
        ];
    }
}
