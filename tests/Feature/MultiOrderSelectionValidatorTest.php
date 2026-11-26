<?php

namespace Tests\Feature;

use App\Services\OrderSelectionValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MultiOrderSelectionValidatorTest extends TestCase
{
    private OrderSelectionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new OrderSelectionValidator();
    }

    /**
     * Preparar una compra con dos categorias.
     */
    private function selection(): array
    {
        return [
            'reservationId' => 'reserva-prueba-123',
            'items' => [
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 2,
                ],
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 3,
                ],
            ],
        ];
    }

    /**
     * Aceptar VIP y General en una sola seleccion.
     */
    public function test_accepts_multiple_ticket_types(): void
    {
        $result = $this->validator->validate(
            $this->selection()
        );

        $this->assertSame(
            'reserva-prueba-123',
            $result['reservationId']
        );

        $this->assertSame(5, $result['quantity']);

        $this->assertSame([
            [
                'ticketTypeId' => 'general',
                'quantity' => 3,
            ],
            [
                'ticketTypeId' => 'vip',
                'quantity' => 2,
            ],
        ], $result['items']);
    }

    /**
     * Permitir hasta diez boletos entre categorias.
     */
    public function test_accepts_ten_tickets_in_total(): void
    {
        $selection = $this->selection();

        $selection['items'][0]['quantity'] = 5;
        $selection['items'][1]['quantity'] = 5;

        $result = $this->validator->validate($selection);

        $this->assertSame(10, $result['quantity']);
        $this->assertCount(2, $result['items']);
    }

    /**
     * Rechazar mas de diez boletos.
     */
    public function test_rejects_more_than_ten_tickets(): void
    {
        $selection = $this->selection();

        $selection['items'][0]['quantity'] = 6;
        $selection['items'][1]['quantity'] = 5;

        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate($selection);
    }

    /**
     * Rechazar categorias repetidas.
     */
    public function test_rejects_duplicate_ticket_types(): void
    {
        $selection = $this->selection();

        $selection['items'][1]['ticketTypeId'] = 'vip';

        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate($selection);
    }

    /**
     * Rechazar precios enviados por el comprador.
     */
    public function test_rejects_unit_price_from_buyer(): void
    {
        $selection = $this->selection();

        $selection['items'][0]['unitPrice'] = 1;

        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate($selection);
    }

    /**
     * Rechazar totales enviados por el comprador.
     */
    public function test_rejects_total_from_buyer(): void
    {
        $selection = $this->selection();

        $selection['total'] = 100;

        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate($selection);
    }

    /**
     * Rechazar campos tradicionales mezclados
     * con una seleccion multiple.
     */
    public function test_rejects_mixed_request_formats(): void
    {
        $selection = $this->selection();

        $selection['ticketTypeId'] = 'vip';
        $selection['quantity'] = 2;

        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate($selection);
    }

    /**
     * Rechazar cantidades negativas.
     */
    public function test_rejects_negative_quantity(): void
    {
        $selection = $this->selection();

        $selection['items'][0]['quantity'] = -1;

        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate($selection);
    }

    /**
     * Rechazar cantidades decimales.
     */
    public function test_rejects_decimal_quantity(): void
    {
        $selection = $this->selection();

        $selection['items'][0]['quantity'] = 1.5;

        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate($selection);
    }

    /**
     * Rechazar una lista de categorias vacia.
     */
    public function test_rejects_empty_items(): void
    {
        $selection = $this->selection();

        $selection['items'] = [];

        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate($selection);
    }

    /**
     * Rechazar identificadores de reserva invalidos.
     */
    public function test_rejects_invalid_reservation_id(): void
    {
        $selection = $this->selection();

        $selection['reservationId'] = 'reserva/invalida';

        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate($selection);
    }

    /**
     * Rechazar identificadores de categoria invalidos.
     */
    public function test_rejects_invalid_ticket_type_id(): void
    {
        $selection = $this->selection();

        $selection['items'][0]['ticketTypeId'] = 'vip/invalido';

        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate($selection);
    }

    /**
     * Aceptar categorias en distinto orden.
     */
    public function test_normalizes_ticket_type_order(): void
    {
        $selection = $this->selection();

        $first = $this->validator->validate(
            $selection
        );

        $selection['items'] = array_reverse(
            $selection['items']
        );

        $second = $this->validator->validate(
            $selection
        );

        $this->assertSame($first, $second);
    }
}
