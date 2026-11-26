<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderQueryController;
use App\Services\OrderAmountCalculator;
use App\Services\OrderReservationValidator;
use App\Services\OrderSelectionValidator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderSummaryConsistencyTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $eventId;
    private string $reservationId;
    private string $orderId;
    private string $userId;
    private string $sellerId;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->eventId = 'evento-resumen-' . $suffix;
        $this->reservationId = 'reserva-resumen-' . $suffix;
        $this->userId = 'comprador-resumen-' . $suffix;

        $this->firestore
            ->collection('users')
            ->document($this->userId)
            ->set([
                'authSubject' => $this->userId,
                'role' => 'customer',
                'status' => 'active',
            ]);
        $this->sellerId = 'vendedor-resumen-' . $suffix;
        $this->key = 'clave-resumen-' . $suffix;

        $this->orderId = hash(
            'sha256',
            $this->userId . ':' . $this->key
        );

        $now = new DateTimeImmutable();
        $timestamp = new Timestamp($now);

        $event = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $event->set([
            'sellerId' => $this->sellerId,
            'name' => 'Evento de resumen',
            'status' => 'published',
            'createdAt' => $timestamp,
            'updatedAt' => $timestamp,
        ]);

        $event
            ->collection('ticketTypes')
            ->document('general')
            ->set([
                'name' => 'General',
                'status' => 'active',
                'price' => 350,
                'currency' => 'MXN',
                'capacity' => 10,
                'sold' => 0,
                'reserved' => 2,
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
            ]);

        $event
            ->collection('reservations')
            ->document($this->reservationId)
            ->set([
                'eventId' => $this->eventId,
                'ticketTypeId' => 'general',
                'userId' => $this->userId,
                'quantity' => 2,
                'unitPrice' => 350,
                'currency' => 'MXN',
                'status' => 'active',
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
                'expiresAt' => new Timestamp(
                    $now->modify('+10 minutes')
                ),
            ]);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore, $this->orderId)) {
                $this->firestore
                    ->collection('orders')
                    ->document($this->orderId)
                    ->delete();
            }

            if (isset($this->firestore, $this->eventId)) {
                $event = $this->firestore
                    ->collection('events')
                    ->document($this->eventId);

                if (isset($this->reservationId)) {
                    $event
                        ->collection('reservations')
                        ->document($this->reservationId)
                        ->delete();
                }

                $event
                    ->collection('ticketTypes')
                    ->document('general')
                    ->delete();

                $event->delete();
            }
        } finally {
            if (isset($this->firestore, $this->userId)) {
                $this->firestore
                    ->collection('users')
                    ->document($this->userId)
                    ->delete();
            }
            parent::tearDown();
        }
    }

    private function request(string $method, string $path): Request
    {
        $request = Request::create($path, $method);

        $request->attributes->set(
            'firebase_uid',
            $this->userId
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        $request->attributes->set(
            'firebase_profile',
            [
                'name' => 'Comprador BluePass',
                'email' => 'comprador@example.com',
                'role' => 'customer',
                'status' => 'active',
            ]
        );

        return $request;
    }

    private function createOrder(): array
    {
        $request = $this->request(
            'POST',
            '/api/events/' . $this->eventId . '/orders'
        );

        $request->request->add([
            'reservationId' => $this->reservationId,
            'ticketTypeId' => 'general',
            'quantity' => 2,
        ]);

        $request->headers->set(
            'Idempotency-Key',
            $this->key
        );

        $response = (new OrderController())->store(
            $request,
            $this->eventId,
            $this->firestore,
            new OrderSelectionValidator(),
            new OrderReservationValidator(),
            new OrderAmountCalculator()
        );

        $this->assertSame(201, $response->getStatusCode());

        return $response->getData(true)['order'];
    }

    public function test_detail_matches_created_order(): void
    {
        $created = $this->createOrder();

        $response = (new OrderQueryController())->show(
            $this->request(
                'GET',
                '/api/orders/' . $this->orderId
            ),
            $this->orderId,
            $this->firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $consulted = $response->getData(true)['order'];

        $this->assertSame($created, $consulted);
    }

    public function test_history_contains_same_order_summary(): void
    {
        $created = $this->createOrder();

        $response = (new OrderQueryController())->index(
            $this->request('GET', '/api/orders'),
            $this->firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $orders = $response->getData(true)['orders'];

        $matching = array_values(
            array_filter(
                $orders,
                fn (array $order): bool =>
                    ($order['id'] ?? null) === $this->orderId
            )
        );

        $this->assertCount(1, $matching);

        $this->assertSame($created, $matching[0]);
    }

    public function test_idempotency_key_is_not_exposed(): void
    {
        $this->createOrder();

        $response = (new OrderQueryController())->show(
            $this->request(
                'GET',
                '/api/orders/' . $this->orderId
            ),
            $this->orderId,
            $this->firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $order = $response->getData(true)['order'];

        $this->assertArrayNotHasKey(
            'idempotencyKey',
            $order
        );

        $saved = $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->snapshot()
            ->data();

        $this->assertSame(
            $this->key,
            $saved['idempotencyKey']
        );
    }
}
