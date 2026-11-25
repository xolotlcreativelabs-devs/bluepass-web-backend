<?php

namespace Tests\Feature;

use App\Services\OrderReservationValidator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Tests\TestCase;

class OrderReservationValidatorTest extends TestCase
{
    private OrderReservationValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new OrderReservationValidator();
    }

    private function selection(): array
    {
        return [
            'reservationId' => 'reserva-123',
            'ticketTypeId' => 'general',
            'quantity' => 2,
        ];
    }

    private function reservation(): array
    {
        return [
            'eventId' => 'evento-123',
            'ticketTypeId' => 'general',
            'userId' => 'comprador-123',
            'quantity' => 2,
            'unitPrice' => 350,
            'currency' => 'MXN',
            'status' => 'active',
            'expiresAt' => new Timestamp(
                new DateTimeImmutable('2030-01-01T12:00:00+00:00')
            ),
        ];
    }

    private function check(
        ?array $selection = null,
        ?array $reservation = null,
        string $userId = 'comprador-123',
        string $eventId = 'evento-123',
        ?DateTimeImmutable $now = null
    ): array {
        return $this->validator->validate(
            $selection ?? $this->selection(),
            $reservation ?? $this->reservation(),
            $userId,
            $eventId,
            $now ?? new DateTimeImmutable(
                '2030-01-01T11:50:00+00:00'
            )
        );
    }

    public function test_accepts_valid_reservation(): void
    {
        $result = $this->check();

        $this->assertTrue($result['valid']);
        $this->assertSame(200, $result['code']);
        $this->assertSame(2, $result['quantity']);
        $this->assertSame(350, $result['unitPrice']);
        $this->assertSame('MXN', $result['currency']);
    }

    public function test_rejects_another_buyers_reservation(): void
    {
        $result = $this->check(userId: 'otro-comprador');

        $this->assertFalse($result['valid']);
        $this->assertSame(403, $result['code']);
    }

    public function test_rejects_different_event(): void
    {
        $result = $this->check(eventId: 'otro-evento');

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    public function test_rejects_different_ticket_type(): void
    {
        $selection = $this->selection();
        $selection['ticketTypeId'] = 'vip';

        $result = $this->check(selection: $selection);

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    public function test_rejects_different_quantity(): void
    {
        $selection = $this->selection();
        $selection['quantity'] = 3;

        $result = $this->check(selection: $selection);

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    public function test_rejects_cancelled_reservation(): void
    {
        $reservation = $this->reservation();
        $reservation['status'] = 'cancelled';

        $result = $this->check(reservation: $reservation);

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    public function test_rejects_expired_status(): void
    {
        $reservation = $this->reservation();
        $reservation['status'] = 'expired';

        $result = $this->check(reservation: $reservation);

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    public function test_rejects_expired_reservation(): void
    {
        $result = $this->check(
            now: new DateTimeImmutable(
                '2030-01-01T12:00:01+00:00'
            )
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    public function test_rejects_reservation_at_exact_expiration(): void
    {
        $result = $this->check(
            now: new DateTimeImmutable(
                '2030-01-01T12:00:00+00:00'
            )
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    public function test_rejects_missing_expiration(): void
    {
        $reservation = $this->reservation();
        unset($reservation['expiresAt']);

        $result = $this->check(reservation: $reservation);

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    public function test_rejects_invalid_price(): void
    {
        $reservation = $this->reservation();
        $reservation['unitPrice'] = -100;

        $result = $this->check(reservation: $reservation);

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    public function test_rejects_invalid_currency(): void
    {
        $reservation = $this->reservation();
        $reservation['currency'] = 'USD';

        $result = $this->check(reservation: $reservation);

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }
}
