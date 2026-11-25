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

class OrderResponseContractTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $eventId;
    private string $reservationId;
    private string $orderId;
    private string $key;
    private string $userId;
    private string $sellerId;

    protected function setUp(): void
    {
        parent::setUp();

        // Las pruebas nunca deben escribir en Firebase real.
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

        $this->eventId = 'evento-contrato-' . $suffix;
        $this->reservationId = 'reserva-contrato-' . $suffix;
        $this->key = 'clave-contrato-' . $suffix;
        $this->userId = 'comprador-contrato-' . $suffix;
        $this->sellerId = 'vendedor-contrato-' . $suffix;

        $this->orderId = hash(
            'sha256',
            $this->userId . ':' . $this->key
        );

        $now = new DateTimeImmutable();
        $createdAt = new Timestamp($now);
        $expiresAt = new Timestamp(
            $now->modify('+10 minutes')
        );

        $event = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $event->set([
            'sellerId' => $this->sellerId,
            'name' => 'Evento de prueba del contrato',
            'status' => 'published',
            'createdAt' => $createdAt,
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
                'updatedAt' => $createdAt,
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
                'createdAt' => $createdAt,
                'updatedAt' => $createdAt,
                'expiresAt' => $expiresAt,
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

    public function test_response_matches_saved_order(): void
    {
        $result = $this->createOrder();

        $this->assertSame(201, $result['status']);

        $body = $result['body'];

        $this->assertArrayHasKey('order', $body);
        $this->assertFalse($body['idempotent']);

        $order = $body['order'];

        $this->assertSame($this->orderId, $order['id']);
        $this->assertSame($this->eventId, $order['eventId']);

        $this->assertSame(
            $this->sellerId,
            $order['sellerId']
        );

        $this->assertSame(
            'online',
            $order['channel']
        );

        $this->assertSame(
            $this->reservationId,
            $order['reservationId']
        );

        $this->assertSame('general', $order['ticketTypeId']);
        $this->assertSame(2, $order['quantity']);
        $this->assertSame(350, $order['unitPrice']);
        $this->assertSame('MXN', $order['currency']);
        $this->assertSame(700, $order['subtotal']);
        $this->assertSame(0, $order['fees']);
        $this->assertSame(700, $order['total']);
        $this->assertSame('pending', $order['status']);

        $this->assertSame(
            [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 2,
                    'unitPrice' => 350,
                    'subtotal' => 700,
                ],
            ],
            $order['items']
        );

        $this->assertSame(
            $this->userId,
            $order['buyer']['id']
        );

        $this->assertSame(
            'Comprador BluePass',
            $order['buyer']['name']
        );

        $this->assertSame(
            'comprador@example.com',
            $order['buyer']['email']
        );

        $this->assertNotEmpty($order['expiresAt']);

        $saved = $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->snapshot();

        $this->assertTrue($saved->exists());

        $data = $saved->data();

        foreach ([
            'eventId',
            'sellerId',
            'channel',
            'items',
            'reservationId',
            'ticketTypeId',
            'quantity',
            'unitPrice',
            'currency',
            'subtotal',
            'fees',
            'total',
            'status',
            'buyer',
        ] as $field) {
            $this->assertSame(
                $data[$field],
                $order[$field],
                'No coincide el campo: ' . $field
            );
        }

        $this->assertSame(
            $this->key,
            $data['idempotencyKey']
        );

        $this->assertArrayNotHasKey(
            'idempotencyKey',
            $order
        );

        $this->assertSame(
            $data['expiresAt']->get()->format(DATE_ATOM),
            $order['expiresAt']
        );
    }

    public function test_idempotent_response_preserves_order_data(): void
    {
        $first = $this->createOrder();
        $second = $this->createOrder();

        $this->assertSame(201, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertTrue($second['body']['idempotent']);

        $this->assertSame(
            $first['body']['order'],
            $second['body']['order']
        );
    }
}
