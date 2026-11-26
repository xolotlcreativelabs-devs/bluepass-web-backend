<?php

namespace Tests\Feature;

use App\Services\TicketInventorySettlement;
use InvalidArgumentException;
use Tests\TestCase;

class TicketInventorySettlementTest extends TestCase
{
    private TicketInventorySettlement $settlement;

    private array $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settlement = new TicketInventorySettlement();

        $this->inventory = [
            'capacity' => 100,
            'sold' => 10,
            'reserved' => 5,
        ];
    }

    public function test_converts_reserved_tickets_into_sold(): void
    {
        $result = $this->settlement->calculate(
            $this->inventory,
            2
        );

        $this->assertSame(100, $result['capacity']);
        $this->assertSame(12, $result['sold']);
        $this->assertSame(3, $result['reserved']);
        $this->assertSame(85, $result['available']);
    }

    public function test_available_inventory_does_not_change(): void
    {
        $before = $this->inventory['capacity']
            - $this->inventory['sold']
            - $this->inventory['reserved'];

        $result = $this->settlement->calculate(
            $this->inventory,
            3
        );

        $this->assertSame($before, $result['available']);
    }

    public function test_can_confirm_all_reserved_tickets(): void
    {
        $result = $this->settlement->calculate(
            $this->inventory,
            5
        );

        $this->assertSame(15, $result['sold']);
        $this->assertSame(0, $result['reserved']);
        $this->assertSame(85, $result['available']);
    }

    public function test_accepts_one_ticket(): void
    {
        $result = $this->settlement->calculate(
            $this->inventory,
            1
        );

        $this->assertSame(11, $result['sold']);
        $this->assertSame(4, $result['reserved']);
    }

    public function test_accepts_ten_tickets(): void
    {
        $this->inventory['reserved'] = 10;

        $result = $this->settlement->calculate(
            $this->inventory,
            10
        );

        $this->assertSame(20, $result['sold']);
        $this->assertSame(0, $result['reserved']);
    }

    public function test_does_not_modify_original_inventory(): void
    {
        $original = $this->inventory;

        $this->settlement->calculate(
            $this->inventory,
            2
        );

        $this->assertSame($original, $this->inventory);
    }

    public function test_rejects_insufficient_reservations(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->settlement->calculate(
            $this->inventory,
            6
        );
    }

    public function test_rejects_zero_quantity(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->settlement->calculate(
            $this->inventory,
            0
        );
    }

    public function test_rejects_negative_quantity(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->settlement->calculate(
            $this->inventory,
            -1
        );
    }

    public function test_rejects_quantity_above_limit(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->settlement->calculate(
            $this->inventory,
            11
        );
    }

    public function test_rejects_negative_capacity(): void
    {
        $this->inventory['capacity'] = -1;

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->settlement->calculate(
            $this->inventory,
            2
        );
    }

    public function test_rejects_negative_sold(): void
    {
        $this->inventory['sold'] = -1;

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->settlement->calculate(
            $this->inventory,
            2
        );
    }

    public function test_rejects_negative_reserved(): void
    {
        $this->inventory['reserved'] = -1;

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->settlement->calculate(
            $this->inventory,
            2
        );
    }

    public function test_rejects_sold_above_capacity(): void
    {
        $this->inventory['sold'] = 101;

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->settlement->calculate(
            $this->inventory,
            2
        );
    }

    public function test_rejects_inventory_above_capacity(): void
    {
        $this->inventory['sold'] = 98;
        $this->inventory['reserved'] = 5;

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->settlement->calculate(
            $this->inventory,
            2
        );
    }

    public function test_rejects_missing_inventory_field(): void
    {
        unset($this->inventory['reserved']);

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->settlement->calculate(
            $this->inventory,
            2
        );
    }

    public function test_rejects_non_integer_inventory(): void
    {
        $this->inventory['sold'] = '10';

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->settlement->calculate(
            $this->inventory,
            2
        );
    }
}
