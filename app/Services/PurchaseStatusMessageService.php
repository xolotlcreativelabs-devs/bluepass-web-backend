<?php

namespace App\Services;

class PurchaseStatusMessageService
{
    /**
     * Preparar el aviso correspondiente al estado del pedido.
     */
    public function forOrder(array $order): array
    {
        $status = $order['status'] ?? null;

        if (!is_string($status)) {
            return $this->unknown();
        }

        return match ($status) {
            'pending' => [
                'type' => 'info',
                'code' => 'payment_pending',
                'title' => 'Pago pendiente',
                'message' => 'Tu pedido esta pendiente de pago. Tus boletos estaran disponibles cuando se confirme el pago.',
                'confirmed' => false,
            ],

            'paid' => $this->paidMessage($order),

            'cancelled' => [
                'type' => 'warning',
                'code' => 'order_cancelled',
                'title' => 'Pedido cancelado',
                'message' => 'Tu pedido fue cancelado. No se emitieron boletos por esta compra.',
                'confirmed' => false,
            ],

            'expired' => [
                'type' => 'warning',
                'code' => 'order_expired',
                'title' => 'Pedido vencido',
                'message' => 'El tiempo de tu reserva termino. Necesitas realizar una nueva seleccion de boletos.',
                'confirmed' => false,
            ],

            default => $this->unknown(),
        };
    }

    /**
     * Confirmar el aviso solamente con un pedido pagado y emitido.
     */
    private function paidMessage(array $order): array
    {
        $ticketIds = $order['issuedTicketIds'] ?? null;
        $paidAt = $order['paidAt'] ?? null;

        if (
            !is_array($ticketIds) ||
            $ticketIds === [] ||
            $paidAt === null
        ) {
            return $this->unknown();
        }

        foreach ($ticketIds as $ticketId) {
            if (
                !is_string($ticketId) ||
                preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $ticketId) !== 1
            ) {
                return $this->unknown();
            }
        }

        return [
            'type' => 'success',
            'code' => 'purchase_confirmed',
            'title' => 'Compra confirmada',
            'message' => 'Tu pago fue confirmado y tus boletos ya estan disponibles en Mis boletos.',
            'confirmed' => true,
        ];
    }

    /**
     * Evitar mostrar confirmaciones cuando el estado no es confiable.
     */
    private function unknown(): array
    {
        return [
            'type' => 'info',
            'code' => 'purchase_status_unknown',
            'title' => 'Estado no disponible',
            'message' => 'No fue posible determinar el resultado de tu compra. Consulta nuevamente el estado de tu pedido.',
            'confirmed' => false,
        ];
    }
}
