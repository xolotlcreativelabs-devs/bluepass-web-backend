<?php

namespace Tests\Feature;

use App\Services\TicketIssuanceValidator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Tests\TestCase;

class TicketIssuanceValidatorTest extends TestCase
{
    private TicketIssuanceValidator $validator;

    private DateTimeImmutable $now;

    private array $order;

    private array $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new TicketIssuanceValidator();

        $this->now = new DateTimeImmutable(
            '2026-11-25T12:00:00-06:00'
        );

        $this->order = [
            'status' => 'pending',
            'userId' => 'comprador-prueba',
            'eventId' => 'evento-prueba',
            'ticketTypeId' => 'general',
            'reservationId' => 'reserva-prueba',
            'quantity' => 2,
        ];

        $this->reservation = [
            'status' => 'active',
            'orderId' => 'pedido-prueba',
            'userId' => 'comprador-prueba',
            'eventId' => 'evento-prueba',
            'ticketTypeId' => 'general',
            'quantity' => 2,
            'expiresAt' => new Timestamp(
                $this->now->modify('+10 minutes')
            ),
        ];
    }

    private function validate(): array
    {
        return $this->validator->validate(
            $this->order,
            $this->reservation,
            'pedido-prueba',
            $this->now
        );
    }

    public function test_accepts_valid_pending_order(): void
    {
        $result = $this->validate();

        $this->assertTrue($result['valid']);
        $this->assertSame(2, $result['quantity']);
        $this->assertSame(
            'comprador-prueba',
            $result['userId']
        );
        $this->assertSame(
            'evento-prueba',
            $result['eventId']
        );
        $this->assertSame(
            'general',
            $result['ticketTypeId']
        );
        $this->assertSame(
            'reserva-prueba',
            $result['reservationId']
        );
    }

    public function test_rejects_order_already_paid(): void
    {
        $this->order['status'] = 'paid';

        $result = $this->validate();

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_cancelled_order(): void
    {
        $this->order['status'] = 'cancelled';

        $result = $this->validate();

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_cancelled_reservation(): void
    {
        $this->reservation['status'] = 'cancelled';

        $result = $this->validate();

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_expired_reservation(): void
    {
        $this->reservation['expiresAt'] = new Timestamp(
            $this->now->modify('-1 minute')
        );

        $result = $this->validate();

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_exact_expiration_time(): void
    {
        $this->reservation['expiresAt'] = new Timestamp(
            $this->now
        );

        $result = $this->validate();

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_another_buyers_reservation(): void
    {
        $this->reservation['userId'] = 'otro-comprador';

        $result = $this->validate();

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_different_event(): void
    {
        $this->reservation['eventId'] = 'otro-evento';

        $result = $this->validate();

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_different_ticket_type(): void
    {
        $this->reservation['ticketTypeId'] = 'vip';

        $result = $this->validate();

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_different_quantity(): void
    {
        $this->reservation['quantity'] = 3;

        $result = $this->validate();

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_reservation_from_another_order(): void
    {
        $this->reservation['orderId'] = 'otro-pedido';

        $result = $this->validate();

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_missing_expiration(): void
    {
        unset($this->reservation['expiresAt']);

        $result = $this->validate();

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_invalid_order_quantity(): void
    {
        $this->order['quantity'] = 0;

        $result = $this->validate();

        $this->assertFalse($result['valid']);
    }
}
