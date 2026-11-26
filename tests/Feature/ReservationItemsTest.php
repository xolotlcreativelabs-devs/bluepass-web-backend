<?php

namespace Tests\Feature;

use App\Services\ReservationItems;
use InvalidArgumentException;
use Tests\TestCase;

class ReservationItemsTest extends TestCase
{
    private ReservationItems $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ReservationItems();
    }

    /**
     * Mantener compatibilidad con reservas tradicionales.
     */
    public function test_accepts_single_ticket_reservation(): void
    {
        $result = $this->service->fromReservation([
            'ticketTypeId' => 'general',
            'quantity' => 3,
        ]);

        $this->assertSame([
            [
                'ticketTypeId' => 'general',
                'quantity' => 3,
            ],
        ], $result);
    }

    /**
     * Aceptar una reserva con VIP y General.
     */
    public function test_accepts_multiple_ticket_types(): void
    {
        $result = $this->service->fromReservation([
            'quantity' => 5,
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

        $this->assertSame([
            [
                'ticketTypeId' => 'general',
                'quantity' => 3,
            ],
            [
                'ticketTypeId' => 'vip',
                'quantity' => 2,
            ],
        ], $result);
    }

    /**
     * Aceptar una reserva multiple de una categoria.
     */
    public function test_accepts_multiple_format_with_one_type(): void
    {
        $result = $this->service->fromReservation([
            'quantity' => 2,
            'items' => [
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 2,
                ],
            ],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('vip', $result[0]['ticketTypeId']);
        $this->assertSame(2, $result[0]['quantity']);
    }

    /**
     * Rechazar cantidades totales inconsistentes.
     */
    public function test_rejects_incorrect_total_quantity(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service->fromReservation([
            'quantity' => 6,
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
    }

    /**
     * Rechazar categorias repetidas.
     */
    public function test_rejects_duplicate_ticket_types(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service->fromReservation([
            'quantity' => 3,
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
     * Rechazar mas de diez boletos.
     */
    public function test_rejects_more_than_ten_tickets(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service->fromReservation([
            'quantity' => 11,
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
     * Rechazar cantidades negativas.
     */
    public function test_rejects_negative_quantity(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service->fromReservation([
            'quantity' => 1,
            'items' => [
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => -1,
                ],
            ],
        ]);
    }

    /**
     * Rechazar identificadores incorrectos.
     */
    public function test_rejects_invalid_ticket_type_id(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service->fromReservation([
            'quantity' => 2,
            'items' => [
                [
                    'ticketTypeId' => 'vip/invalido',
                    'quantity' => 2,
                ],
            ],
        ]);
    }

    /**
     * Rechazar listas vacias.
     */
    public function test_rejects_empty_items(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service->fromReservation([
            'quantity' => 1,
            'items' => [],
        ]);
    }

    /**
     * Rechazar reservas tradicionales invalidas.
     */
    public function test_rejects_invalid_single_reservation(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service->fromReservation([
            'ticketTypeId' => 'general',
            'quantity' => 0,
        ]);
    }

    /**
     * Comprobar que el orden no cambie el resultado.
     */
    public function test_normalizes_multiple_item_order(): void
    {
        $first = $this->service->fromReservation([
            'quantity' => 5,
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

        $second = $this->service->fromReservation([
            'quantity' => 5,
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

        $this->assertSame($first, $second);
    }
}
