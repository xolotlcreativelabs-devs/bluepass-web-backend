<?php

namespace Tests\Feature;

use App\Services\MultiTicketIssuanceValidator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Tests\TestCase;

class MultiTicketIssuanceValidatorTest extends TestCase
{
    private MultiTicketIssuanceValidator $validator;

    private string $orderId = 'pedido-multiple-prueba';

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new MultiTicketIssuanceValidator();
    }

    /**
     * Preparar un pedido multiple valido.
     */
    private function order(): array
    {
        return [
            'userId' => 'comprador-prueba',
            'eventId' => 'evento-prueba',
            'reservationId' => 'reserva-prueba',
            'status' => 'pending',
            'currency' => 'MXN',
            'quantity' => 5,
            'items' => [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 3,
                    'unitPrice' => 50000,
                    'subtotal' => 150000,
                ],
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 2,
                    'unitPrice' => 150000,
                    'subtotal' => 300000,
                ],
            ],
            'subtotal' => 450000,
            'fees' => 0,
            'total' => 450000,
        ];
    }

    /**
     * Preparar la reserva vinculada al pedido.
     */
    private function reservation(): array
    {
        return [
            'orderId' => $this->orderId,
            'userId' => 'comprador-prueba',
            'eventId' => 'evento-prueba',
            'status' => 'active',
            'currency' => 'MXN',
            'quantity' => 5,
            'items' => [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 3,
                    'unitPrice' => 50000,
                    'currency' => 'MXN',
                ],
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 2,
                    'unitPrice' => 150000,
                    'currency' => 'MXN',
                ],
            ],
            'expiresAt' => new Timestamp(
                new DateTimeImmutable('+10 minutes')
            ),
        ];
    }

    /**
     * Validar un pedido y su reserva.
     */
    private function validate(
        ?array $order = null,
        ?array $reservation = null,
        ?DateTimeImmutable $now = null
    ): array {
        return $this->validator->validate(
            $order ?? $this->order(),
            $reservation ?? $this->reservation(),
            $this->orderId,
            $now ?? new DateTimeImmutable()
        );
    }

    /**
     * Aceptar un pedido de varias categorias.
     */
    public function test_accepts_valid_multiple_order(): void
    {
        $result = $this->validate();

        $this->assertTrue(
            $result['valid'],
            $result['message']
        );

        $this->assertSame(
            'comprador-prueba',
            $result['userId']
        );

        $this->assertSame(
            'evento-prueba',
            $result['eventId']
        );

        $this->assertSame(5, $result['quantity']);

        $this->assertSame([
            [
                'ticketTypeId' => 'general',
                'quantity' => 3,
                'unitPrice' => 50000,
            ],
            [
                'ticketTypeId' => 'vip',
                'quantity' => 2,
                'unitPrice' => 150000,
            ],
        ], $result['items']);
    }

    /**
     * Rechazar un pedido que ya fue pagado.
     */
    public function test_rejects_paid_order(): void
    {
        $order = $this->order();
        $order['status'] = 'paid';

        $this->assertFalse(
            $this->validate(order: $order)['valid']
        );
    }

    /**
     * Rechazar una reserva cancelada.
     */
    public function test_rejects_cancelled_reservation(): void
    {
        $reservation = $this->reservation();
        $reservation['status'] = 'cancelled';

        $this->assertFalse(
            $this->validate(reservation: $reservation)['valid']
        );
    }

    /**
     * Rechazar una reserva vencida.
     */
    public function test_rejects_expired_reservation(): void
    {
        $reservation = $this->reservation();

        $reservation['expiresAt'] = new Timestamp(
            new DateTimeImmutable('-1 minute')
        );

        $this->assertFalse(
            $this->validate(reservation: $reservation)['valid']
        );
    }

    /**
     * Rechazar una reserva vinculada a otro pedido.
     */
    public function test_rejects_another_order_reservation(): void
    {
        $reservation = $this->reservation();
        $reservation['orderId'] = 'otro-pedido';

        $this->assertFalse(
            $this->validate(reservation: $reservation)['valid']
        );
    }

    /**
     * Rechazar una reserva de otro comprador.
     */
    public function test_rejects_another_buyer(): void
    {
        $reservation = $this->reservation();
        $reservation['userId'] = 'otro-comprador';

        $this->assertFalse(
            $this->validate(reservation: $reservation)['valid']
        );
    }

    /**
     * Rechazar cantidades diferentes entre pedido y reserva.
     */
    public function test_rejects_different_quantities(): void
    {
        $order = $this->order();

        $order['items'][0]['quantity'] = 2;
        $order['items'][0]['subtotal'] = 100000;

        $this->assertFalse(
            $this->validate(order: $order)['valid']
        );
    }

    /**
     * Rechazar precios alterados en el pedido.
     */
    public function test_rejects_modified_unit_price(): void
    {
        $order = $this->order();

        $order['items'][1]['unitPrice'] = 1;
        $order['items'][1]['subtotal'] = 2;

        $this->assertFalse(
            $this->validate(order: $order)['valid']
        );
    }

    /**
     * Rechazar subtotales incorrectos por categoria.
     */
    public function test_rejects_invalid_item_subtotal(): void
    {
        $order = $this->order();
        $order['items'][0]['subtotal'] = 1;

        $this->assertFalse(
            $this->validate(order: $order)['valid']
        );
    }

    /**
     * Rechazar el subtotal total alterado.
     */
    public function test_rejects_modified_subtotal(): void
    {
        $order = $this->order();
        $order['subtotal'] = 100;

        $this->assertFalse(
            $this->validate(order: $order)['valid']
        );
    }

    /**
     * Rechazar un total que no corresponda.
     */
    public function test_rejects_invalid_total(): void
    {
        $order = $this->order();
        $order['total'] = 1;

        $this->assertFalse(
            $this->validate(order: $order)['valid']
        );
    }

    /**
     * Rechazar cantidades mayores al limite.
     */
    public function test_rejects_more_than_ten_tickets(): void
    {
        $order = $this->order();
        $reservation = $this->reservation();

        $order['items'][0]['quantity'] = 9;
        $order['items'][0]['subtotal'] = 450000;
        $order['quantity'] = 11;
        $order['subtotal'] = 750000;
        $order['total'] = 750000;

        $reservation['items'][0]['quantity'] = 9;
        $reservation['quantity'] = 11;

        $this->assertFalse(
            $this->validate(
                order: $order,
                reservation: $reservation
            )['valid']
        );
    }

    /**
     * Rechazar categorias duplicadas.
     */
    public function test_rejects_duplicate_ticket_types(): void
    {
        $order = $this->order();
        $reservation = $this->reservation();

        $order['items'][1]['ticketTypeId'] = 'general';
        $reservation['items'][1]['ticketTypeId'] = 'general';

        $this->assertFalse(
            $this->validate(
                order: $order,
                reservation: $reservation
            )['valid']
        );
    }

    /**
     * Rechazar monedas distintas de MXN.
     */
    public function test_rejects_invalid_currency(): void
    {
        $order = $this->order();
        $order['currency'] = 'USD';

        $this->assertFalse(
            $this->validate(order: $order)['valid']
        );
    }

    /**
     * Rechazar precios negativos en la reserva.
     */
    public function test_rejects_negative_reserved_price(): void
    {
        $reservation = $this->reservation();

        $reservation['items'][0]['unitPrice'] = -1;

        $this->assertFalse(
            $this->validate(reservation: $reservation)['valid']
        );
    }

    /**
     * Aceptar categorias aunque lleguen en otro orden.
     */
    public function test_accepts_reordered_items(): void
    {
        $order = $this->order();
        $reservation = $this->reservation();

        $order['items'] = array_reverse($order['items']);
        $reservation['items'] = array_reverse(
            $reservation['items']
        );

        $result = $this->validate(
            order: $order,
            reservation: $reservation
        );

        $this->assertTrue(
            $result['valid'],
            $result['message']
        );

        $this->assertSame(
            'general',
            $result['items'][0]['ticketTypeId']
        );

        $this->assertSame(
            'vip',
            $result['items'][1]['ticketTypeId']
        );
    }

    /**
     * Rechazar datos de un evento diferente.
     */
    public function test_rejects_different_event(): void
    {
        $reservation = $this->reservation();
        $reservation['eventId'] = 'otro-evento';

        $this->assertFalse(
            $this->validate(reservation: $reservation)['valid']
        );
    }

    /**
     * Rechazar reservas sin categorias.
     */
    public function test_rejects_reservation_without_items(): void
    {
        $reservation = $this->reservation();

        unset($reservation['items']);

        $this->assertFalse(
            $this->validate(reservation: $reservation)['valid']
        );
    }
}
