<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderController;
use App\Services\OrderAmountCalculator;
use App\Services\OrderReservationValidator;
use App\Services\OrderSelectionValidator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderDocumentedContractTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $eventId;
    private string $reservationId;
    private string $userId;
    private string $sellerId;
    private string $key;
    private string $orderId;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'Esta prueba requiere Firestore Emulator.'
            );
        }

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'evento-documentado-' . $suffix;
        $this->reservationId = 'reserva-documentada-' . $suffix;
        $this->userId = 'comprador-documentado-' . $suffix;

        $this->firestore
            ->collection('users')
            ->document($this->userId)
            ->set([
                'authSubject' => $this->userId,
                'role' => 'customer',
                'status' => 'active',
            ]);
        $this->sellerId = 'vendedor-documentado-' . $suffix;
        $this->key = 'pedido-documentado-' . $suffix;

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
            'name' => 'Evento de contrato documentado',
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

    private function createOrder(): array
    {
        $request = Request::create(
            '/api/events/' . $this->eventId . '/orders',
            'POST',
            [
                'reservationId' => $this->reservationId,
                'ticketTypeId' => 'general',
                'quantity' => 2,
            ],
            [],
            [],
            [
                'HTTP_IDEMPOTENCY_KEY' => $this->key,
            ]
        );

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

        $response = (new OrderController())->store(
            $request,
            $this->eventId,
            $this->firestore,
            new OrderSelectionValidator(),
            new OrderReservationValidator(),
            new OrderAmountCalculator()
        );

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getData(true),
        ];
    }

    public function test_order_contains_documented_fields(): void
    {
        $result = $this->createOrder();

        $this->assertSame(201, $result['status']);

        $saved = $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->snapshot();

        $this->assertTrue($saved->exists());

        $order = $saved->data();

        $this->assertSame(
            $this->sellerId,
            $order['sellerId'] ?? null
        );

        $this->assertSame(
            'online',
            $order['channel'] ?? null
        );

        $this->assertSame(
            $this->key,
            $order['idempotencyKey'] ?? null
        );

        $this->assertSame(
            [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 2,
                    'unitPrice' => 350,
                    'subtotal' => 700,
                ],
            ],
            $order['items'] ?? null
        );

        $this->assertSame('MXN', $order['currency']);
        $this->assertSame(700, $order['total']);
        $this->assertSame('pending', $order['status']);
    }

    public function test_response_contains_documented_summary(): void
    {
        $result = $this->createOrder();

        $this->assertSame(201, $result['status']);

        $order = $result['body']['order'];

        $this->assertSame(
            $this->sellerId,
            $order['sellerId'] ?? null
        );

        $this->assertSame(
            'online',
            $order['channel'] ?? null
        );

        $this->assertSame(
            'general',
            $order['ticketTypeId'] ?? null
        );

        $this->assertSame(2, $order['quantity']);
        $this->assertSame(700, $order['subtotal']);
        $this->assertSame(0, $order['fees']);
        $this->assertSame(700, $order['total']);

        $this->assertSame(
            [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 2,
                    'unitPrice' => 350,
                    'subtotal' => 700,
                ],
            ],
            $order['items'] ?? null
        );

        $this->assertSame(
            $this->userId,
            $order['buyer']['id']
        );

        $this->assertNotEmpty($order['expiresAt']);
    }
}
