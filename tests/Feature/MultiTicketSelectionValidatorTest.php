<?php

namespace Tests\Feature;

use App\Services\MultiTicketSelectionValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MultiTicketSelectionValidatorTest extends TestCase
{
    private MultiTicketSelectionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new MultiTicketSelectionValidator();
    }

    /**
     * Validar una compra de boletos VIP y Generales.
     */
    public function test_accepts_multiple_ticket_types(): void
    {
        $result = $this->validator->validate([
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
        ]);

        $this->assertSame(5, $result['quantity']);

        $this->assertCount(2, $result['items']);

        $this->assertSame(
            [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 3,
                ],
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 2,
                ],
            ],
            $result['items']
        );
    }

    /**
     * Permitir una sola categoria.
     */
    public function test_accepts_single_ticket_type(): void
    {
        $result = $this->validator->validate([
            'items' => [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 1,
                ],
            ],
        ]);

        $this->assertSame(1, $result['quantity']);
        $this->assertCount(1, $result['items']);
        $this->assertSame(
            'general',
            $result['items'][0]['ticketTypeId']
        );
    }

    /**
     * Impedir categorias repetidas.
     */
    public function test_rejects_duplicate_ticket_types(): void
    {
        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate([
            'items' => [
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 2,
                ],
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 1,
                ],
            ],
        ]);
    }

    /**
     * Limitar el total a diez boletos.
     */
    public function test_rejects_more_than_ten_tickets(): void
    {
        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate([
            'items' => [
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 6,
                ],
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 5,
                ],
            ],
        ]);
    }

    /**
     * Permitir exactamente diez boletos.
     */
    public function test_accepts_exactly_ten_tickets(): void
    {
        $result = $this->validator->validate([
            'items' => [
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 5,
                ],
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 5,
                ],
            ],
        ]);

        $this->assertSame(10, $result['quantity']);
        $this->assertCount(2, $result['items']);
    }

    /**
     * Rechazar cantidades iguales a cero.
     */
    public function test_rejects_zero_quantity(): void
    {
        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate([
            'items' => [
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 0,
                ],
            ],
        ]);
    }

    /**
     * Rechazar identificadores invalidos.
     */
    public function test_rejects_invalid_ticket_type(): void
    {
        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate([
            'items' => [
                [
                    'ticketTypeId' => 'vip/invalido',
                    'quantity' => 2,
                ],
            ],
        ]);
    }

    /**
     * No aceptar precios enviados por el comprador.
     */
    public function test_rejects_client_supplied_price(): void
    {
        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate([
            'items' => [
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 2,
                    'price' => 1,
                ],
            ],
        ]);
    }

    /**
     * No aceptar totales enviados por el comprador.
     */
    public function test_rejects_client_supplied_total(): void
    {
        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate([
            'items' => [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 2,
                ],
            ],
            'total' => 1,
        ]);
    }

    /**
     * Rechazar una lista vacia.
     */
    public function test_rejects_empty_items(): void
    {
        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate([
            'items' => [],
        ]);
    }

    /**
     * No permitir cantidades negativas.
     */
    public function test_rejects_negative_quantity(): void
    {
        $this->expectException(
            ValidationException::class
        );

        $this->validator->validate([
            'items' => [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => -2,
                ],
            ],
        ]);
    }

    /**
     * Ordenar sin depender del orden enviado.
     */
    public function test_normalizes_ticket_type_order(): void
    {
        $first = $this->validator->validate([
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
        ]);

        $second = $this->validator->validate([
            'items' => [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 3,
                ],
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 2,
                ],
            ],
        ]);

        $this->assertSame(
            $first,
            $second
        );
    }
}
