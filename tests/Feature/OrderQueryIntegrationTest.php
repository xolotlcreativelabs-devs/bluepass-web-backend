<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderQueryController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderQueryIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $ownOrderDocument;

    private DocumentReference $otherOrderDocument;

    private DocumentReference $expiredOrderDocument;

    private string $ownOrderId;

    private string $otherOrderId;

    private string $expiredOrderId;

    protected function setUp(): void
    {
        parent::setUp();

        // Impedir que las pruebas utilicen Firebase real.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'Esta prueba solo puede ejecutarse en Firestore Emulator.'
            );
        }

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        $suffix = bin2hex(random_bytes(8));

        $this->ownOrderId = 'consulta-propia-' . $suffix;
        $this->otherOrderId = 'consulta-ajena-' . $suffix;
        $this->expiredOrderId = 'consulta-vencida-' . $suffix;

        $orders = $this->firestore->collection('orders');

        $this->ownOrderDocument = $orders
            ->document($this->ownOrderId);

        $this->otherOrderDocument = $orders
            ->document($this->otherOrderId);

        $this->expiredOrderDocument = $orders
            ->document($this->expiredOrderId);

        $now = new DateTimeImmutable();

        $this->ownOrderDocument->set(
            $this->orderData(
                'comprador-consulta-uno',
                $now->modify('+10 minutes')
            )
        );

        $this->otherOrderDocument->set(
            $this->orderData(
                'comprador-consulta-dos',
                $now->modify('+10 minutes')
            )
        );

        $this->expiredOrderDocument->set(
            $this->orderData(
                'comprador-consulta-uno',
                $now->modify('-1 hour')
            )
        );
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->ownOrderDocument)) {
                $this->ownOrderDocument->delete();
            }

            if (isset($this->otherOrderDocument)) {
                $this->otherOrderDocument->delete();
            }

            if (isset($this->expiredOrderDocument)) {
                $this->expiredOrderDocument->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Preparar un pedido temporal de prueba.
     */
    private function orderData(
        string $userId,
        DateTimeImmutable $expiresAt
    ): array {
        $now = new DateTimeImmutable();

        return [
            'userId' => $userId,
            'eventId' => 'evento-consulta',
            'reservationId' => 'reserva-consulta',
            'ticketTypeId' => 'general',
            'quantity' => 2,
            'unitPrice' => 350,
            'currency' => 'MXN',
            'subtotal' => 700,
            'fees' => 0,
            'total' => 700,
            'status' => 'pending',
            'buyer' => [
                'id' => $userId,
                'name' => 'Comprador de prueba',
                'email' => 'prueba@example.com',
            ],
            'expiresAt' => new Timestamp($expiresAt),
            'createdAt' => new Timestamp($now),
            'updatedAt' => new Timestamp($now),
        ];
    }

    /**
     * Preparar la identidad autenticada para el controlador.
     */
    private function request(
        string $path,
        string $userId = 'comprador-consulta-uno',
        string $role = 'customer'
    ): Request {
        $request = Request::create($path, 'GET');

        $request->attributes->set('firebase_uid', $userId);
        $request->attributes->set('firebase_role', $role);

        return $request;
    }

    private function listOrders(
        string $userId = 'comprador-consulta-uno'
    ): JsonResponse {
        return (new OrderQueryController())->index(
            $this->request('/api/orders', $userId),
            $this->firestore
        );
    }

    private function showOrder(
        string $orderId,
        string $userId = 'comprador-consulta-uno'
    ): JsonResponse {
        return (new OrderQueryController())->show(
            $this->request(
                '/api/orders/' . $orderId,
                $userId
            ),
            $orderId,
            $this->firestore
        );
    }

    public function test_lists_only_own_orders(): void
    {
        $response = $this->listOrders();

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);
        $ids = array_column($body['orders'], 'id');

        $this->assertContains($this->ownOrderId, $ids);
        $this->assertContains($this->expiredOrderId, $ids);
        $this->assertNotContains($this->otherOrderId, $ids);

        foreach ($body['orders'] as $order) {
            $this->assertSame(
                'comprador-consulta-uno',
                $order['buyer']['id']
            );
        }
    }

    public function test_another_customer_lists_only_their_orders(): void
    {
        $response = $this->listOrders(
            'comprador-consulta-dos'
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);
        $ids = array_column($body['orders'], 'id');

        $this->assertContains($this->otherOrderId, $ids);
        $this->assertNotContains($this->ownOrderId, $ids);
        $this->assertNotContains($this->expiredOrderId, $ids);
    }

    public function test_customer_can_view_own_order(): void
    {
        $response = $this->showOrder(
            $this->ownOrderId
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(
            $this->ownOrderId,
            $body['order']['id']
        );

        $this->assertSame(
            700,
            $body['order']['total']
        );

        $this->assertSame(
            'pending',
            $body['order']['status']
        );
    }

    public function test_customer_cannot_view_another_users_order(): void
    {
        $response = $this->showOrder(
            $this->otherOrderId
        );

        $this->assertSame(404, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertArrayNotHasKey('order', $body);
    }

    public function test_expired_order_is_displayed_as_expired(): void
    {
        $response = $this->showOrder(
            $this->expiredOrderId
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(
            'expired',
            $body['order']['status']
        );

        // Consultar no debe modificar el documento almacenado.
        $storedOrder = $this->expiredOrderDocument
            ->snapshot()
            ->data();

        $this->assertSame(
            'pending',
            $storedOrder['status']
        );
    }

    public function test_rejects_invalid_order_id(): void
    {
        $response = $this->showOrder('id-invalido!');

        $this->assertSame(404, $response->getStatusCode());
    }
}
