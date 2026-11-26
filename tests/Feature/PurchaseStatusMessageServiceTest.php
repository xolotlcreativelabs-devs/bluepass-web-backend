<?php

namespace Tests\Feature;

use App\Services\PurchaseStatusMessageService;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Tests\TestCase;

class PurchaseStatusMessageServiceTest extends TestCase
{
    private PurchaseStatusMessageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PurchaseStatusMessageService();
    }

    public function test_pending_order_shows_payment_pending(): void
    {
        $result = $this->service->forOrder([
            'status' => 'pending',
        ]);

        $this->assertSame('info', $result['type']);
        $this->assertSame('payment_pending', $result['code']);
        $this->assertSame('Pago pendiente', $result['title']);
        $this->assertFalse($result['confirmed']);
    }

    public function test_paid_order_with_issued_tickets_is_confirmed(): void
    {
        $result = $this->service->forOrder([
            'status' => 'paid',
            'paidAt' => new Timestamp(new DateTimeImmutable()),
            'issuedTicketIds' => [
                'boleto-uno',
                'boleto-dos',
            ],
        ]);

        $this->assertSame('success', $result['type']);
        $this->assertSame('purchase_confirmed', $result['code']);
        $this->assertSame('Compra confirmada', $result['title']);
        $this->assertTrue($result['confirmed']);
    }

    public function test_paid_order_without_tickets_is_not_confirmed(): void
    {
        $result = $this->service->forOrder([
            'status' => 'paid',
            'paidAt' => new Timestamp(new DateTimeImmutable()),
            'issuedTicketIds' => [],
        ]);

        $this->assertSame('purchase_status_unknown', $result['code']);
        $this->assertFalse($result['confirmed']);
    }

    public function test_paid_order_without_payment_date_is_not_confirmed(): void
    {
        $result = $this->service->forOrder([
            'status' => 'paid',
            'paidAt' => null,
            'issuedTicketIds' => ['boleto-uno'],
        ]);

        $this->assertSame('purchase_status_unknown', $result['code']);
        $this->assertFalse($result['confirmed']);
    }

    public function test_paid_order_with_invalid_ticket_id_is_not_confirmed(): void
    {
        $result = $this->service->forOrder([
            'status' => 'paid',
            'paidAt' => new Timestamp(new DateTimeImmutable()),
            'issuedTicketIds' => ['boleto-invalido!'],
        ]);

        $this->assertSame('purchase_status_unknown', $result['code']);
        $this->assertFalse($result['confirmed']);
    }

    public function test_cancelled_order_shows_warning(): void
    {
        $result = $this->service->forOrder([
            'status' => 'cancelled',
        ]);

        $this->assertSame('warning', $result['type']);
        $this->assertSame('order_cancelled', $result['code']);
        $this->assertFalse($result['confirmed']);
    }

    public function test_expired_order_shows_warning(): void
    {
        $result = $this->service->forOrder([
            'status' => 'expired',
        ]);

        $this->assertSame('warning', $result['type']);
        $this->assertSame('order_expired', $result['code']);
        $this->assertFalse($result['confirmed']);
    }

    public function test_unknown_status_is_not_confirmed(): void
    {
        $result = $this->service->forOrder([
            'status' => 'processing',
        ]);

        $this->assertSame('purchase_status_unknown', $result['code']);
        $this->assertFalse($result['confirmed']);
    }

    public function test_missing_status_is_not_confirmed(): void
    {
        $result = $this->service->forOrder([]);

        $this->assertSame('purchase_status_unknown', $result['code']);
        $this->assertFalse($result['confirmed']);
    }

    public function test_invalid_status_type_is_not_confirmed(): void
    {
        $result = $this->service->forOrder([
            'status' => 123,
        ]);

        $this->assertSame('purchase_status_unknown', $result['code']);
        $this->assertFalse($result['confirmed']);
    }

    public function test_all_messages_have_required_fields(): void
    {
        $orders = [
            ['status' => 'pending'],
            [
                'status' => 'paid',
                'paidAt' => new Timestamp(new DateTimeImmutable()),
                'issuedTicketIds' => ['boleto-uno'],
            ],
            ['status' => 'cancelled'],
            ['status' => 'expired'],
            ['status' => 'unknown'],
        ];

        foreach ($orders as $order) {
            $result = $this->service->forOrder($order);

            $this->assertArrayHasKey('type', $result);
            $this->assertArrayHasKey('code', $result);
            $this->assertArrayHasKey('title', $result);
            $this->assertArrayHasKey('message', $result);
            $this->assertArrayHasKey('confirmed', $result);

            $this->assertIsString($result['message']);
            $this->assertIsBool($result['confirmed']);
        }
    }
}
