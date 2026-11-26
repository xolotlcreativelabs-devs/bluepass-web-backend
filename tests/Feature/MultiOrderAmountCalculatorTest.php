<?php

namespace Tests\Feature;

use App\Services\OrderAmountCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class MultiOrderAmountCalculatorTest extends TestCase
{
    private OrderAmountCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new OrderAmountCalculator();
    }

    /**
     * Preparar una seleccion de dos VIP y tres Generales.
     * Todos los precios estan expresados en centavos.
     */
    private function items(): array
    {
        return [
            [
                'ticketTypeId' => 'vip',
                'quantity' => 2,
                'unitPrice' => 150000,
                'currency' => 'MXN',
            ],
            [
                'ticketTypeId' => 'general',
                'quantity' => 3,
                'unitPrice' => 50000,
                'currency' => 'MXN',
            ],
        ];
    }

    /**
     * Calcular correctamente un pedido de varias categorias.
     */
    public function test_calculates_multiple_ticket_types(): void
    {
        config()->set('bluepass.service_fee_percent', '0');

        $result = $this->calculator->calculateItems(
            $this->items()
        );

        $this->assertSame(5, $result['quantity']);
        $this->assertSame('MXN', $result['currency']);
        $this->assertSame(450000, $result['subtotal']);
        $this->assertSame(0, $result['fees']);
        $this->assertSame(450000, $result['total']);

        $this->assertSame([
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
        ], $result['items']);
    }

    /**
     * Calcular la comision sobre el subtotal del pedido.
     */
    public function test_calculates_one_fee_for_entire_order(): void
    {
        config()->set('bluepass.service_fee_percent', '3');

        $result = $this->calculator->calculateItems(
            $this->items()
        );

        $this->assertSame(450000, $result['subtotal']);
        $this->assertSame(13500, $result['fees']);
        $this->assertSame(463500, $result['total']);
    }

    /**
     * Evitar diferencias por calcular comisiones separadas.
     */
    public function test_rounds_fee_on_combined_subtotal(): void
    {
        config()->set('bluepass.service_fee_percent', '25');

        $result = $this->calculator->calculateItems([
            [
                'ticketTypeId' => 'vip',
                'quantity' => 1,
                'unitPrice' => 1,
                'currency' => 'MXN',
            ],
            [
                'ticketTypeId' => 'general',
                'quantity' => 1,
                'unitPrice' => 1,
                'currency' => 'MXN',
            ],
        ]);

        $this->assertSame(2, $result['subtotal']);
        $this->assertSame(1, $result['fees']);
        $this->assertSame(3, $result['total']);
    }

    /**
     * Mantener compatibilidad con compras de una categoria.
     */
    public function test_accepts_single_ticket_type(): void
    {
        config()->set('bluepass.service_fee_percent', '0');

        $result = $this->calculator->calculateItems([
            [
                'ticketTypeId' => 'general',
                'quantity' => 2,
                'unitPrice' => 50000,
                'currency' => 'MXN',
            ],
        ]);

        $this->assertSame(2, $result['quantity']);
        $this->assertSame(100000, $result['subtotal']);
        $this->assertCount(1, $result['items']);
    }

    /**
     * Permitir exactamente diez boletos entre varias categorias.
     */
    public function test_accepts_ten_tickets_in_total(): void
    {
        config()->set('bluepass.service_fee_percent', '0');

        $result = $this->calculator->calculateItems([
            [
                'ticketTypeId' => 'vip',
                'quantity' => 5,
                'unitPrice' => 150000,
                'currency' => 'MXN',
            ],
            [
                'ticketTypeId' => 'general',
                'quantity' => 5,
                'unitPrice' => 50000,
                'currency' => 'MXN',
            ],
        ]);

        $this->assertSame(10, $result['quantity']);
        $this->assertSame(1000000, $result['subtotal']);
    }

    /**
     * Rechazar mas de diez boletos en un pedido.
     */
    public function test_rejects_more_than_ten_tickets(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->calculator->calculateItems([
            [
                'ticketTypeId' => 'vip',
                'quantity' => 6,
                'unitPrice' => 150000,
                'currency' => 'MXN',
            ],
            [
                'ticketTypeId' => 'general',
                'quantity' => 5,
                'unitPrice' => 50000,
                'currency' => 'MXN',
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

        $this->calculator->calculateItems([
            [
                'ticketTypeId' => 'vip',
                'quantity' => 1,
                'unitPrice' => 150000,
                'currency' => 'MXN',
            ],
            [
                'ticketTypeId' => 'vip',
                'quantity' => 2,
                'unitPrice' => 150000,
                'currency' => 'MXN',
            ],
        ]);
    }

    /**
     * Rechazar monedas diferentes de MXN.
     */
    public function test_rejects_invalid_currency(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $items = $this->items();
        $items[1]['currency'] = 'USD';

        $this->calculator->calculateItems($items);
    }

    /**
     * Rechazar precios unitarios negativos.
     */
    public function test_rejects_negative_price(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $items = $this->items();
        $items[0]['unitPrice'] = -100;

        $this->calculator->calculateItems($items);
    }

    /**
     * Rechazar precios superiores al limite permitido.
     */
    public function test_rejects_price_above_limit(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $items = $this->items();
        $items[0]['unitPrice'] = 10000001;

        $this->calculator->calculateItems($items);
    }

    /**
     * Rechazar una lista vacia de boletos.
     */
    public function test_rejects_empty_items(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->calculator->calculateItems([]);
    }

    /**
     * Rechazar cantidades no enteras.
     */
    public function test_rejects_invalid_quantity(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $items = $this->items();
        $items[0]['quantity'] = 'dos';

        $this->calculator->calculateItems($items);
    }

    /**
     * El orden de las categorias no debe cambiar el importe.
     */
    public function test_normalizes_item_order(): void
    {
        config()->set('bluepass.service_fee_percent', '3');

        $first = $this->calculator->calculateItems(
            $this->items()
        );

        $second = $this->calculator->calculateItems(
            array_reverse($this->items())
        );

        $this->assertSame($first, $second);
    }
}
