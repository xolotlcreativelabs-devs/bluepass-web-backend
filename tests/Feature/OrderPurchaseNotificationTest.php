<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderQueryController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderPurchaseNotificationTest extends TestCase
{
    private FirestoreClient $firestore;

    private OrderQueryController $controller;

    private string $buyerId;

    private string $otherBuyerId;

    private string $eventId;

    private array $orderIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Utilizar exclusivamente Firestore Emulator.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'Estas pruebas requieren Firestore Emulator.'
            );
        }

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        $this->controller = new OrderQueryController();

        $suffix = bin2hex(random_bytes(8));

        $this->buyerId = 'buyer-notification-' . $suffix;
        $this->otherBuyerId = 'other-notification-' . $suffix;
        $this->eventId = 'event-notification-' . $suffix;

        $now = new DateTimeImmutable();

        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->set([
                'name' => 'Evento BluePass',
                'venue' => 'Auditorio Principal',
                'timezone' => 'America/Mexico_City',
                'date' => new Timestamp(
                    $now->modify('+5 days')
                ),
                'endAt' => new Timestamp(
                    $now->modify('+5 days +3 hours')
                ),
                'status' => 'published',
            ]);

        $this->createOrder('pending', [
            'status' => 'pending',
            'expiresAt' => new Timestamp(
                $now->modify('+15 minutes')
            ),
        ]);

        $this->createOrder('paid', [
            'status' => 'paid',
            'paidAt' => new Timestamp($now),
            'issuedTicketIds' => [
                'ticket-confirmed-' . $suffix,
            ],
        ]);

        $this->createOrder('cancelled', [
            'status' => 'cancelled',
        ]);

        $this->createOrder('expired', [
            'status' => 'pending',
            'expiresAt' => new Timestamp(
                $now->modify('-15 minutes')
            ),
        ]);

        $this->createOrder('inconsistent', [
            'status' => 'paid',
            'paidAt' => new Timestamp($now),
            'issuedTicketIds' => [],
        ]);

        $this->createOrder('other', [
            'status' => 'paid',
            'paidAt' => new Timestamp($now),
            'issuedTicketIds' => [
                'ticket-other-' . $suffix,
            ],
        ], $this->otherBuyerId);
    }

    /**
     * Crear un pedido de prueba.
     */
    private function createOrder(
        string $name,
        array $data,
        ?string $userId = null
    ): void {
        $orderId = 'order-notification-' . $name . '-' .
            bin2hex(random_bytes(6));

        $this->orderIds[$name] = $orderId;

        $this->firestore
            ->collection('orders')
            ->document($orderId)
            ->set(array_merge([
                'userId' => $userId ?? $this->buyerId,
                'eventId' => $this->eventId,
                'ticketTypeId' => 'general',
                'quantity' => 1,
                'unitPrice' => 350,
                'currency' => 'MXN',
                'subtotal' => 350,
                'fees' => 0,
                'total' => 350,
                'createdAt' => new Timestamp(
                    new DateTimeImmutable()
                ),
            ], $data));
    }

    /**
     * Preparar una solicitud del comprador.
     */
    private function customerRequest(
        ?string $userId = null
    ): Request {
        $request = Request::create(
            '/api/orders',
            'GET'
        );

        $request->attributes->set(
            'firebase_uid',
            $userId ?? $this->buyerId
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        return $request;
    }

    /**
     * Consultar el detalle del pedido.
     */
    private function detail(string $name): array
    {
        $response = $this->controller->show(
            $this->customerRequest(),
            $this->orderIds[$name],
            $this->firestore
        );

        $this->assertSame(
            200,
            $response->getStatusCode()
        );

        return $response->getData(true);
    }

    public function test_pending_order_has_pending_notification(): void
    {
        $data = $this->detail('pending');

        $this->assertSame(
            'pending',
            $data['order']['status']
        );

        $this->assertSame(
            'payment_pending',
            $data['notification']['code']
        );

        $this->assertFalse(
            $data['notification']['confirmed']
        );
    }

    public function test_paid_order_has_confirmed_notification(): void
    {
        $data = $this->detail('paid');

        $this->assertSame(
            'paid',
            $data['order']['status']
        );

        $this->assertSame(
            'purchase_confirmed',
            $data['notification']['code']
        );

        $this->assertTrue(
            $data['notification']['confirmed']
        );
    }

    public function test_cancelled_order_has_warning(): void
    {
        $data = $this->detail('cancelled');

        $this->assertSame(
            'order_cancelled',
            $data['notification']['code']
        );

        $this->assertFalse(
            $data['notification']['confirmed']
        );
    }

    public function test_expired_reservation_has_expired_notification(): void
    {
        $data = $this->detail('expired');

        $this->assertSame(
            'expired',
            $data['order']['status']
        );

        $this->assertSame(
            'order_expired',
            $data['notification']['code']
        );

        $this->assertFalse(
            $data['notification']['confirmed']
        );
    }

    public function test_inconsistent_paid_order_is_not_confirmed(): void
    {
        $data = $this->detail('inconsistent');

        $this->assertSame(
            'purchase_status_unknown',
            $data['notification']['code']
        );

        $this->assertFalse(
            $data['notification']['confirmed']
        );
    }

    public function test_detail_preserves_original_order_contract(): void
    {
        $data = $this->detail('paid');

        $this->assertArrayHasKey('order', $data);
        $this->assertArrayHasKey('history', $data);
        $this->assertArrayHasKey('notification', $data);

        $this->assertArrayNotHasKey(
            'notification',
            $data['order']
        );

        $this->assertSame(
            350,
            $data['order']['total']
        );

        $this->assertSame(
            'MXN',
            $data['order']['currency']
        );
    }

    public function test_history_groups_include_notifications(): void
    {
        $response = $this->controller->index(
            $this->customerRequest(),
            $this->firestore
        );

        $this->assertSame(
            200,
            $response->getStatusCode()
        );

        $data = $response->getData(true);

        $this->assertSame(5, $data['count']);

        $this->assertArrayHasKey('groups', $data);

        $this->assertArrayHasKey(
            'upcoming',
            $data['groups']
        );

        $this->assertArrayHasKey(
            'pending',
            $data['groups']
        );

        $this->assertArrayHasKey(
            'cancelled',
            $data['groups']
        );

        $notifications = [];

        foreach ($data['groups'] as $group) {
            foreach ($group as $order) {
                $this->assertArrayHasKey(
                    'notification',
                    $order
                );

                $notifications[$order['id']] =
                    $order['notification']['code'];
            }
        }

        $this->assertSame(
            'payment_pending',
            $notifications[$this->orderIds['pending']]
        );

        $this->assertSame(
            'purchase_confirmed',
            $notifications[$this->orderIds['paid']]
        );

        $this->assertSame(
            'order_cancelled',
            $notifications[$this->orderIds['cancelled']]
        );

        $this->assertSame(
            'order_expired',
            $notifications[$this->orderIds['expired']]
        );

        $this->assertSame(
            'purchase_status_unknown',
            $notifications[$this->orderIds['inconsistent']]
        );

        $this->assertArrayNotHasKey(
            $this->orderIds['other'],
            $notifications
        );

        // La lista original de pedidos no cambia.
        foreach ($data['orders'] as $order) {
            $this->assertArrayNotHasKey(
                'notification',
                $order
            );
        }
    }

    public function test_other_buyers_order_is_not_accessible(): void
    {
        $response = $this->controller->show(
            $this->customerRequest(),
            $this->orderIds['other'],
            $this->firestore
        );

        $this->assertSame(
            404,
            $response->getStatusCode()
        );

        $data = $response->getData(true);

        $this->assertArrayNotHasKey(
            'notification',
            $data
        );
    }

    /**
     * Eliminar los documentos creados por las pruebas.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                foreach ($this->orderIds as $orderId) {
                    $this->firestore
                        ->collection('orders')
                        ->document($orderId)
                        ->delete();
                }

                if (isset($this->eventId)) {
                    $this->firestore
                        ->collection('events')
                        ->document($this->eventId)
                        ->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
