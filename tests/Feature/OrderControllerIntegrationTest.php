<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderController;
use App\Services\OrderAmountCalculator;
use App\Services\OrderReservationValidator;
use App\Services\OrderSelectionValidator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderControllerIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $eventDocument;

    private DocumentReference $ticketDocument;

    private DocumentReference $reservationDocument;

    private array $orderDocuments = [];

    private string $eventId;

    private string $reservationId;

    private string $idempotencyKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Estas pruebas solo se permiten en Firestore Emulator.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'Debes configurar Firestore Emulator antes de ejecutar estas pruebas.'
            );
        }

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        $this->firestore
            ->collection('users')
            ->document('comprador-hu05')
            ->set([
                'authSubject' => 'comprador-hu05',
                'role' => 'customer',
                'status' => 'active',
            ]);

        $this->eventId = 'pedido-hu05-'
            . bin2hex(random_bytes(8));

        $this->reservationId = 'reserva-hu05-'
            . bin2hex(random_bytes(8));

        $this->idempotencyKey = 'pedido-prueba-'
            . bin2hex(random_bytes(8));

        $this->eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $this->ticketDocument = $this->eventDocument
            ->collection('ticketTypes')
            ->document('general');

        $this->reservationDocument = $this->eventDocument
            ->collection('reservations')
            ->document($this->reservationId);

        $now = new DateTimeImmutable();

        $this->eventDocument->set([
            'sellerId' => 'vendedor-hu05',
            'name' => 'Evento temporal de pedidos',
            'status' => 'published',
            'createdAt' => new Timestamp($now),
        ]);

        $this->ticketDocument->set([
            'name' => 'General',
            'status' => 'active',
            'price' => 350,
            'currency' => 'MXN',
            'capacity' => 10,
            'sold' => 0,
            'reserved' => 2,
            'createdAt' => new Timestamp($now),
        ]);

        $this->reservationDocument->set([
            'eventId' => $this->eventId,
            'ticketTypeId' => 'general',
            'userId' => 'comprador-hu05',
            'quantity' => 2,
            'unitPrice' => 350,
            'currency' => 'MXN',
            'status' => 'active',
            'createdAt' => new Timestamp($now),
            'updatedAt' => new Timestamp($now),
            'expiresAt' => new Timestamp(
                $now->modify('+10 minutes')
            ),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            // Eliminar solamente los pedidos de esta prueba.
            foreach ($this->orderDocuments as $document) {
                $document->delete();
            }

            if (isset($this->reservationDocument)) {
                $this->reservationDocument->delete();
            }

            if (isset($this->ticketDocument)) {
                $this->ticketDocument->delete();
            }

            if (isset($this->eventDocument)) {
                $this->eventDocument->delete();
            }
        } finally {
            if (isset($this->firestore)) {
                $this->firestore
                    ->collection('users')
                    ->document('comprador-hu05')
                    ->delete();
            }

            parent::tearDown();
        }
    }

    private function selection(): array
    {
        return [
            'reservationId' => $this->reservationId,
            'ticketTypeId' => 'general',
            'quantity' => 2,
        ];
    }

    /**
     * Ejecutar el controlador real utilizando Firestore Emulator.
     */
    private function createOrder(
        ?array $selection = null,
        ?string $key = null,
        string $userId = 'comprador-hu05'
    ): JsonResponse {
        $key ??= $this->idempotencyKey;

        $orderId = hash('sha256', $userId . ':' . $key);

        $this->orderDocuments[$orderId] = $this->firestore
            ->collection('orders')
            ->document($orderId);

        $request = Request::create(
            '/api/events/' . $this->eventId . '/orders',
            'POST',
            $selection ?? $this->selection(),
            [],
            [],
            [
                'HTTP_IDEMPOTENCY_KEY' => $key,
            ]
        );

        $request->attributes->set(
            'firebase_uid',
            $userId
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        $request->attributes->set(
            'firebase_profile',
            [
                'name' => 'Comprador de prueba',
                'email' => 'comprador@example.com',
                'role' => 'customer',
                'status' => 'active',
            ]
        );

        $controller = new OrderController();

        return $controller->store(
            $request,
            $this->eventId,
            $this->firestore,
            new OrderSelectionValidator(),
            new OrderReservationValidator(),
            new OrderAmountCalculator()
        );
    }

    public function test_deleting_account_cannot_create_order(): void
    {
        $this->firestore
            ->collection('users')
            ->document('comprador-hu05')
            ->update([
                [
                    'path' => 'status',
                    'value' => 'deletion_pending',
                ],
            ]);

        $response = $this->createOrder();

        $this->assertSame(
            403,
            $response->getStatusCode(),
            $response->getContent()
        );

        $orderId = hash(
            'sha256',
            'comprador-hu05:' . $this->idempotencyKey
        );

        $this->assertFalse(
            $this->firestore
                ->collection('orders')
                ->document($orderId)
                ->snapshot()
                ->exists()
        );

        $reservation = $this->reservationDocument
            ->snapshot()
            ->data();

        $this->assertArrayNotHasKey(
            'orderId',
            $reservation
        );

        $inventory = $this->ticketDocument
            ->snapshot()
            ->data();

        $this->assertSame(2, $inventory['reserved']);
        $this->assertSame(0, $inventory['sold']);
    }

    public function test_creates_order_and_links_reservation(): void
    {
        $response = $this->createOrder();

        $this->assertSame(201, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertFalse($body['idempotent']);

        $this->assertSame(
            'pending',
            $body['order']['status']
        );

        $this->assertSame(
            $this->reservationId,
            $body['order']['reservationId']
        );

        $this->assertSame(
            2,
            $body['order']['quantity']
        );

        $this->assertSame(
            700,
            $body['order']['subtotal']
        );

        $this->assertSame(
            0,
            $body['order']['fees']
        );

        $this->assertSame(
            700,
            $body['order']['total']
        );

        $this->assertSame(
            'vendedor-hu05',
            $body['order']['sellerId']
        );

        $orderId = $body['order']['id'];

        $orderSnapshot = $this->firestore
            ->collection('orders')
            ->document($orderId)
            ->snapshot();

        $this->assertTrue($orderSnapshot->exists());

        $reservation = $this->reservationDocument
            ->snapshot()
            ->data();

        $this->assertSame(
            $orderId,
            $reservation['orderId']
        );

        // Crear el pedido no debe reservar boletos otra vez.
        $inventory = $this->ticketDocument
            ->snapshot()
            ->data();

        $this->assertSame(10, $inventory['capacity']);
        $this->assertSame(0, $inventory['sold']);
        $this->assertSame(2, $inventory['reserved']);
    }

    public function test_same_key_returns_same_order(): void
    {
        $first = $this->createOrder();
        $second = $this->createOrder();

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());

        $firstBody = $first->getData(true);
        $secondBody = $second->getData(true);

        $this->assertTrue($secondBody['idempotent']);

        $this->assertSame(
            $firstBody['order']['id'],
            $secondBody['order']['id']
        );

        $inventory = $this->ticketDocument
            ->snapshot()
            ->data();

        $this->assertSame(2, $inventory['reserved']);
    }

    public function test_same_reservation_cannot_create_second_order(): void
    {
        $first = $this->createOrder();

        $second = $this->createOrder(
            key: 'otra-clave-'
                . bin2hex(random_bytes(8))
        );

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(409, $second->getStatusCode());
    }

    public function test_rejects_different_quantity(): void
    {
        $selection = $this->selection();
        $selection['quantity'] = 3;

        $response = $this->createOrder($selection);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_rejects_another_buyer(): void
    {
        $response = $this->createOrder(
            userId: 'otro-comprador'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_rejects_expired_reservation(): void
    {
        $this->reservationDocument->update([
            [
                'path' => 'expiresAt',
                'value' => new Timestamp(
                    new DateTimeImmutable('-1 hour')
                ),
            ],
        ]);

        $response = $this->createOrder();

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_rejects_price_sent_by_buyer(): void
    {
        $selection = $this->selection();
        $selection['price'] = 1;

        $response = $this->createOrder($selection);

        $this->assertSame(422, $response->getStatusCode());
    }
}
