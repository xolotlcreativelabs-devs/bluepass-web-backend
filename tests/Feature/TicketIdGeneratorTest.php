<?php

namespace Tests\Feature;

use App\Services\TicketIdGenerator;
use InvalidArgumentException;
use Tests\TestCase;

class TicketIdGeneratorTest extends TestCase
{
    private TicketIdGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new TicketIdGenerator();
    }

    public function test_generates_valid_ticket_id(): void
    {
        $ticketId = $this->generator->generate(
            'pedido-prueba',
            1
        );

        $this->assertMatchesRegularExpression(
            '/^ticket_[a-f0-9]{64}$/D',
            $ticketId
        );
    }

    public function test_same_order_and_position_generate_same_id(): void
    {
        $first = $this->generator->generate(
            'pedido-prueba',
            1
        );

        $second = $this->generator->generate(
            'pedido-prueba',
            1
        );

        $this->assertSame($first, $second);
    }

    public function test_different_positions_generate_different_ids(): void
    {
        $first = $this->generator->generate(
            'pedido-prueba',
            1
        );

        $second = $this->generator->generate(
            'pedido-prueba',
            2
        );

        $this->assertNotSame($first, $second);
    }

    public function test_different_orders_generate_different_ids(): void
    {
        $first = $this->generator->generate(
            'pedido-uno',
            1
        );

        $second = $this->generator->generate(
            'pedido-dos',
            1
        );

        $this->assertNotSame($first, $second);
    }

    public function test_generates_ids_for_multiple_tickets(): void
    {
        $ticketIds = $this->generator->generateForOrder(
            'pedido-prueba',
            3
        );

        $this->assertCount(3, $ticketIds);

        $this->assertCount(
            3,
            array_unique($ticketIds)
        );

        $this->assertSame(
            $this->generator->generate('pedido-prueba', 1),
            $ticketIds[0]
        );

        $this->assertSame(
            $this->generator->generate('pedido-prueba', 2),
            $ticketIds[1]
        );

        $this->assertSame(
            $this->generator->generate('pedido-prueba', 3),
            $ticketIds[2]
        );
    }

    public function test_repeating_order_generates_same_list(): void
    {
        $first = $this->generator->generateForOrder(
            'pedido-prueba',
            3
        );

        $second = $this->generator->generateForOrder(
            'pedido-prueba',
            3
        );

        $this->assertSame($first, $second);
    }

    public function test_accepts_one_ticket(): void
    {
        $ticketIds = $this->generator->generateForOrder(
            'pedido-prueba',
            1
        );

        $this->assertCount(1, $ticketIds);
    }

    public function test_accepts_ten_tickets(): void
    {
        $ticketIds = $this->generator->generateForOrder(
            'pedido-prueba',
            10
        );

        $this->assertCount(10, $ticketIds);

        $this->assertCount(
            10,
            array_unique($ticketIds)
        );
    }

    public function test_rejects_invalid_order_id(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->generator->generate(
            'pedido/con/barra',
            1
        );
    }

    public function test_rejects_empty_order_id(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->generator->generate('', 1);
    }

    public function test_rejects_zero_position(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->generator->generate(
            'pedido-prueba',
            0
        );
    }

    public function test_rejects_position_above_limit(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->generator->generate(
            'pedido-prueba',
            11
        );
    }

    public function test_rejects_zero_quantity(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->generator->generateForOrder(
            'pedido-prueba',
            0
        );
    }

    public function test_rejects_quantity_above_limit(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->generator->generateForOrder(
            'pedido-prueba',
            11
        );
    }
}
