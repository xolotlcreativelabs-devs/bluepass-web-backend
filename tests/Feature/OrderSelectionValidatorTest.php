<?php

namespace Tests\Feature;

use App\Services\OrderSelectionValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderSelectionValidatorTest extends TestCase
{
    private OrderSelectionValidator $selectionValidator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->selectionValidator = new OrderSelectionValidator();
    }

    public function test_accepts_valid_ticket_selection(): void
    {
        $result = $this->selectionValidator->validate([
            'reservationId' => 'reserva-123',
            'ticketTypeId' => 'general',
            'quantity' => 2,
        ]);

        $this->assertSame([
            'reservationId' => 'reserva-123',
            'ticketTypeId' => 'general',
            'quantity' => 2,
        ], $result);
    }

    public function test_rejects_missing_reservation_id(): void
    {
        $this->assertInvalidSelection([
            'ticketTypeId' => 'general',
            'quantity' => 2,
        ], 'reservationId');
    }

    public function test_rejects_invalid_reservation_id(): void
    {
        $this->assertInvalidSelection([
            'reservationId' => '../reserva',
            'ticketTypeId' => 'general',
            'quantity' => 2,
        ], 'reservationId');
    }

    public function test_rejects_missing_ticket_type(): void
    {
        $this->assertInvalidSelection([
            'reservationId' => 'reserva-123',
            'quantity' => 2,
        ], 'ticketTypeId');
    }

    public function test_rejects_invalid_ticket_type(): void
    {
        $this->assertInvalidSelection([
            'reservationId' => 'reserva-123',
            'ticketTypeId' => 'general/otro',
            'quantity' => 2,
        ], 'ticketTypeId');
    }

    public function test_rejects_zero_quantity(): void
    {
        $this->assertInvalidSelection([
            'reservationId' => 'reserva-123',
            'ticketTypeId' => 'general',
            'quantity' => 0,
        ], 'quantity');
    }

    public function test_rejects_negative_quantity(): void
    {
        $this->assertInvalidSelection([
            'reservationId' => 'reserva-123',
            'ticketTypeId' => 'general',
            'quantity' => -1,
        ], 'quantity');
    }

    public function test_rejects_quantity_above_limit(): void
    {
        $this->assertInvalidSelection([
            'reservationId' => 'reserva-123',
            'ticketTypeId' => 'general',
            'quantity' => 11,
        ], 'quantity');
    }

    public function test_rejects_decimal_quantity(): void
    {
        $this->assertInvalidSelection([
            'reservationId' => 'reserva-123',
            'ticketTypeId' => 'general',
            'quantity' => 1.5,
        ], 'quantity');
    }

    public function test_rejects_text_quantity(): void
    {
        $this->assertInvalidSelection([
            'reservationId' => 'reserva-123',
            'ticketTypeId' => 'general',
            'quantity' => 'dos',
        ], 'quantity');
    }

    public function test_rejects_price_from_buyer(): void
    {
        $this->assertInvalidSelection([
            'reservationId' => 'reserva-123',
            'ticketTypeId' => 'general',
            'quantity' => 2,
            'price' => 1,
        ], 'fields');
    }

    public function test_rejects_total_from_buyer(): void
    {
        $this->assertInvalidSelection([
            'reservationId' => 'reserva-123',
            'ticketTypeId' => 'general',
            'quantity' => 2,
            'total' => 1,
        ], 'fields');
    }

    public function test_rejects_unexpected_status(): void
    {
        $this->assertInvalidSelection([
            'reservationId' => 'reserva-123',
            'ticketTypeId' => 'general',
            'quantity' => 2,
            'status' => 'paid',
        ], 'fields');
    }

    /**
     * Comprobar que un campo sea rechazado por el validador.
     */
    private function assertInvalidSelection(
        array $input,
        string $field
    ): void {
        try {
            $this->selectionValidator->validate($input);

            $this->fail(
                'Se esperaba un error de validacion.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                $field,
                $exception->errors()
            );
        }
    }
}
