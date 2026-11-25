<?php

namespace Tests\Feature;

use App\Services\OrderAmountCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class OrderAmountCalculatorTest extends TestCase
{
    private OrderAmountCalculator $calculator;

    private mixed $originalFeePercent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalFeePercent = config(
            'bluepass.service_fee_percent',
            '0'
        );

        config()->set(
            'bluepass.service_fee_percent',
            '0'
        );

        $this->calculator = new OrderAmountCalculator();
    }

    protected function tearDown(): void
    {
        config()->set(
            'bluepass.service_fee_percent',
            $this->originalFeePercent
        );

        parent::tearDown();
    }

    public function test_calculates_subtotal_and_total(): void
    {
        $result = $this->calculator->calculate(2, 350, 'MXN');

        $this->assertSame(2, $result['quantity']);
        $this->assertSame(350, $result['unitPrice']);
        $this->assertSame('MXN', $result['currency']);
        $this->assertSame(700, $result['subtotal']);
        $this->assertSame(0, $result['fees']);
        $this->assertSame(700, $result['total']);
    }

    public function test_accepts_one_ticket(): void
    {
        $result = $this->calculator->calculate(1, 250, 'MXN');

        $this->assertSame(250, $result['total']);
    }

    public function test_accepts_ten_tickets(): void
    {
        $result = $this->calculator->calculate(10, 100, 'MXN');

        $this->assertSame(1000, $result['total']);
    }

    public function test_accepts_free_tickets(): void
    {
        $result = $this->calculator->calculate(2, 0, 'MXN');

        $this->assertSame(0, $result['subtotal']);
        $this->assertSame(0, $result['fees']);
        $this->assertSame(0, $result['total']);
    }

    public function test_calculates_maximum_allowed_amount(): void
    {
        $result = $this->calculator->calculate(
            10,
            10000000,
            'MXN'
        );

        $this->assertSame(100000000, $result['total']);
    }

    public function test_rejects_zero_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(0, 350, 'MXN');
    }

    public function test_rejects_negative_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(-1, 350, 'MXN');
    }

    public function test_rejects_more_than_ten_tickets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(11, 350, 'MXN');
    }

    public function test_rejects_negative_price(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(2, -100, 'MXN');
    }

    public function test_rejects_price_above_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(2, 10000001, 'MXN');
    }

    public function test_rejects_invalid_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(2, 350, 'USD');
    }

    public function test_calculates_three_percent_fee(): void
    {
        config()->set('bluepass.service_fee_percent', '3');

        $result = $this->calculator->calculate(2, 350, 'MXN');

        $this->assertSame(700, $result['subtotal']);
        $this->assertSame(21, $result['fees']);
        $this->assertSame(721, $result['total']);
    }

    public function test_calculates_five_percent_fee(): void
    {
        config()->set('bluepass.service_fee_percent', '5');

        $result = $this->calculator->calculate(2, 350, 'MXN');

        $this->assertSame(700, $result['subtotal']);
        $this->assertSame(35, $result['fees']);
        $this->assertSame(735, $result['total']);
    }

    public function test_calculates_ten_percent_fee(): void
    {
        config()->set('bluepass.service_fee_percent', '10');

        $result = $this->calculator->calculate(2, 350, 'MXN');

        $this->assertSame(700, $result['subtotal']);
        $this->assertSame(70, $result['fees']);
        $this->assertSame(770, $result['total']);
    }

    public function test_accepts_decimal_percentage(): void
    {
        config()->set('bluepass.service_fee_percent', '2.50');

        $result = $this->calculator->calculate(2, 350, 'MXN');

        $this->assertSame(18, $result['fees']);
        $this->assertSame(718, $result['total']);
    }

    public function test_rounds_fees_to_nearest_cent(): void
    {
        config()->set('bluepass.service_fee_percent', '5');

        $result = $this->calculator->calculate(1, 11, 'MXN');

        $this->assertSame(11, $result['subtotal']);
        $this->assertSame(1, $result['fees']);
        $this->assertSame(12, $result['total']);
    }

    public function test_rejects_negative_fee_percentage(): void
    {
        config()->set('bluepass.service_fee_percent', '-5');

        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(2, 350, 'MXN');
    }

    public function test_rejects_fee_percentage_above_limit(): void
    {
        config()->set('bluepass.service_fee_percent', '101');

        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(2, 350, 'MXN');
    }

    public function test_rejects_invalid_fee_percentage(): void
    {
        config()->set('bluepass.service_fee_percent', 'cinco');

        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(2, 350, 'MXN');
    }
}
