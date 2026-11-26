<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderQueryController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderHistoryClassificationTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $buyerId;

    private string $otherBuyerId;

    private array $eventIds = [];

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

        $suffix = bin2hex(random_bytes(8));

        $this->buyerId = 'comprador-historial-' . $suffix;
        $this->otherBuyerId = 'otro-historial-' . $suffix;

        $now = new DateTimeImmutable();

        $this->createEvent(
            'future',
            $suffix,
            $now->modify('+5 days'),
            $now->modify('+5 days +3 hours')
        );

        $this->createEvent(
            'ongoing',
            $suffix,
            $now->modify('-1 hour'),
            $now->modify('+2 hours')
        );

        $this->createEvent(
            'past',
            $suffix,
            $now->modify('-5 days'),
            $now->modify('-5 days +3 hours')
        );

        $this->createEvent(
            'invalid',
            $suffix,
            $now->modify('+1 day'),
            $now->modify('-1 day')
        );

        $this->createOrder(
            'upcoming',
            $suffix,
            $this->buyerId,
            $this->eventIds['future'],
            'paid'
        );

        $this->createOrder(
            'ongoing',
            $suffix,
            $this->buyerId,
            $this->eventIds['ongoing'],
            'paid'
        );

        $this->createOrder(
            'past',
            $suffix,
            $this->buyerId,
            $this->eventIds['past'],
            'paid'
        );

        $this->createOrder(
            'pending',
            $suffix,
            $this->buyerId,
            $this->eventIds['future'],
            'pending'
        );

        $this->createOrder(
            'cancelled',
            $suffix,
            $this->buyerId,
            $this->eventIds['future'],
            'cancelled'
        );

        $this->createOrder(
            'expired',
            $suffix,
            $this->buyerId,
            $this->eventIds['future'],
            'expired'
        );

        $this->createOrder(
            'unknown',
            $suffix,
            $this->buyerId,
            $this->eventIds['invalid'],
            'paid'
        );

        $this->createOrder(
            'other',
            $suffix,
            $this->otherBuyerId,
            $this->eventIds['future'],
            'paid'
        );
    }

    /**
     * Crear un evento con fechas para clasificarlo.
     */
    private function createEvent(
        string $name,
        string $suffix,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): void {
        $eventId = 'evento-historial-' . $name . '-' . $suffix;

        $this->eventIds[$name] = $eventId;

        $this->firestore
            ->collection('events')
            ->document($eventId)
            ->set([
                'name' => 'Evento ' . $name,
                'venue' => 'Recinto BluePass',
                'timezone' => 'America/Mexico_City',
                'date' => new Timestamp($start),
                'endAt' => new Timestamp($end),
                'status' => 'published',
            ]);
    }

    /**
     * Crear un pedido temporal.
     */
    private function createOrder(
        string $name,
        string $suffix,
        string $userId,
        string $eventId,
        string $status
    ): void {
        $orderId = 'pedido-historial-' . $name . '-' . $suffix;

        $this->orderIds[$name] = $orderId;

        $now = new DateTimeImmutable();

        $this->firestore
            ->collection('orders')
            ->document($orderId)
            ->set([
                'userId' => $userId,
                'eventId' => $eventId,
                'sellerId' => 'vendedor-prueba',
                'channel' => 'online',
                'ticketTypeId' => 'general',
                'items' => [
                    [
                        'ticketTypeId' => 'general',
                        'quantity' => 2,
                        'unitPrice' => 350,
                        'subtotal' => 700,
                    ],
                ],
                'quantity' => 2,
                'unitPrice' => 350,
                'currency' => 'MXN',
                'subtotal' => 700,
                'fees' => 0,
                'total' => 700,
                'status' => $status,
                'buyer' => [
                    'id' => $userId,
                    'name' => 'Comprador de prueba',
                    'email' => 'comprador@example.com',
                ],
                'expiresAt' => new Timestamp(
                    $now->modify('+10 minutes')
                ),
                'createdAt' => new Timestamp($now),
                'updatedAt' => new Timestamp($now),
                'paidAt' => $status === 'paid'
                    ? new Timestamp($now)
                    : null,
            ]);
    }

    /**
     * Consultar el historial del comprador.
     */
    private function history(
        ?string $userId = null
    ): JsonResponse {
        $request = Request::create('/api/orders', 'GET');

        $request->attributes->set(
            'firebase_uid',
            $userId ?? $this->buyerId
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        return (new OrderQueryController())->index(
            $request,
            $this->firestore
        );
    }

    /**
     * Consultar el detalle de un pedido.
     */
    private function detail(
        string $name,
        ?string $userId = null
    ): JsonResponse {
        $orderId = $this->orderIds[$name];

        $request = Request::create(
            '/api/orders/' . $orderId,
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

        return (new OrderQueryController())->show(
            $request,
            $orderId,
            $this->firestore
        );
    }

    public function test_history_returns_all_groups(): void
    {
        $response = $this->history();

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(7, $body['count']);

        foreach (
            [
                'upcoming',
                'ongoing',
                'past',
                'pending',
                'cancelled',
                'unknown',
            ] as $group
        ) {
            $this->assertArrayHasKey($group, $body['groups']);
        }

        $this->assertCount(2, $body['groups']['cancelled']);

        $this->assertCount(1, $body['groups']['upcoming']);
        $this->assertCount(1, $body['groups']['ongoing']);
        $this->assertCount(1, $body['groups']['past']);
        $this->assertCount(1, $body['groups']['pending']);
        $this->assertCount(1, $body['groups']['unknown']);
    }

    public function test_future_event_is_upcoming(): void
    {
        $response = $this->detail('upcoming');

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(
            'upcoming',
            $body['history']['group']
        );

        $this->assertSame(
            'paid',
            $body['order']['status']
        );

        $this->assertSame(
            'Evento future',
            $body['history']['event']['name']
        );

        $this->assertNotEmpty(
            $body['history']['event']['date']
        );

        $this->assertNotEmpty(
            $body['history']['event']['endAt']
        );

        $this->assertNotEmpty($body['history']['paidAt']);
    }

    public function test_current_event_is_ongoing(): void
    {
        $response = $this->detail('ongoing');

        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame(
            'ongoing',
            $response->getData(true)['history']['group']
        );
    }

    public function test_finished_event_is_past(): void
    {
        $response = $this->detail('past');

        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame(
            'past',
            $response->getData(true)['history']['group']
        );
    }

    public function test_pending_order_is_separate(): void
    {
        $response = $this->detail('pending');

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(
            'pending',
            $body['history']['group']
        );

        $this->assertSame(
            'pending',
            $body['order']['status']
        );
    }

    public function test_cancelled_and_expired_orders_are_separate(): void
    {
        foreach (['cancelled', 'expired'] as $name) {
            $response = $this->detail($name);

            $this->assertSame(200, $response->getStatusCode());

            $this->assertSame(
                'cancelled',
                $response->getData(true)['history']['group']
            );
        }
    }

    public function test_invalid_event_dates_are_unknown(): void
    {
        $response = $this->detail('unknown');

        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame(
            'unknown',
            $response->getData(true)['history']['group']
        );
    }

    public function test_history_excludes_other_buyers_orders(): void
    {
        $response = $this->history();

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $ids = array_column($body['orders'], 'id');

        $this->assertNotContains(
            $this->orderIds['other'],
            $ids
        );

        // Verificar tambien los pedidos agrupados.
        foreach ($body['groups'] as $groupOrders) {
            $groupIds = array_column($groupOrders, 'id');

            $this->assertNotContains(
                $this->orderIds['other'],
                $groupIds
            );
        }
    }

    public function test_customer_cannot_view_other_buyers_detail(): void
    {
        $response = $this->detail('other');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_order_detail_preserves_purchase_amounts(): void
    {
        $response = $this->detail('upcoming');

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);
        $order = $body['order'];

        $this->assertSame(2, $order['quantity']);
        $this->assertSame(700, $order['subtotal']);
        $this->assertSame(0, $order['fees']);
        $this->assertSame(700, $order['total']);
        $this->assertSame('MXN', $order['currency']);

        $this->assertNotEmpty($body['history']['paidAt']);

        // No modificar el resumen original del pedido.
        $this->assertArrayNotHasKey('group', $order);
        $this->assertArrayNotHasKey('event', $order);
        $this->assertArrayNotHasKey('paidAt', $order);
        $this->assertArrayNotHasKey('createdAt', $order);
    }

    /**
     * Eliminar los documentos temporales.
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

                foreach ($this->eventIds as $eventId) {
                    $this->firestore
                        ->collection('events')
                        ->document($eventId)
                        ->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
