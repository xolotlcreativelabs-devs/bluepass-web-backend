<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderController;
use App\Services\MultiTicketReservationService;
use App\Services\MultiTicketSelectionValidator;
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

class MultiOrderControllerIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $eventDocument;

    private string $eventId;

    private string $reservationId;

    private string $idempotencyKey;

    private array $ticketDocuments = [];

    private array $orderDocuments = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca utilizar Firebase de produccion.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'Estas pruebas requieren Firestore Emulator.'
            );
        }

        config()->set('bluepass.service_fee_percent', '0');

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        // Crear el comprador que utilizara esta prueba.
        $this->firestore
            ->collection('users')
            ->document('comprador-prueba')
            ->set([
                'authSubject' => 'comprador-prueba',
                'name' => 'Comprador de prueba',
                'email' => 'comprador@example.test',
                'role' => 'customer',
                'status' => 'active',
            ]);

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'test-multi-order-' . $suffix;
        $this->idempotencyKey = 'clave-multi-' . $suffix;

        $this->eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $now = new DateTimeImmutable();

        $this->eventDocument->set([
            'sellerId' => 'vendedor-prueba',
            'name' => 'Evento de pedidos multiples',
            'status' => 'published',
            'date' => new Timestamp(
                $now->modify('+10 days')
            ),
            'createdAt' => new Timestamp($now),
        ]);

        foreach ([
            'vip' => 150000,
            'general' => 50000,
        ] as $typeId => $price) {
            $document = $this->eventDocument
                ->collection('ticketTypes')
                ->document($typeId);

            $document->set([
                'name' => $typeId,
                'status' => 'active',
                'price' => $price,
                'currency' => 'MXN',
                'capacity' => 10,
                'sold' => 0,
                'reserved' => 0,
                'saleStartAt' => new Timestamp(
                    $now->modify('-1 hour')
                ),
                'saleEndAt' => new Timestamp(
                    $now->modify('+5 days')
                ),
            ]);

            $this->ticketDocuments[$typeId] = $document;
        }

        // Crear una reserva multiple real antes del pedido.
        $service = new MultiTicketReservationService(
            $this->firestore,
            new MultiTicketSelectionValidator()
        );

        $result = $service->create(
            $this->eventId,
            'comprador-prueba',
            [
                'items' => [
                    [
                        'ticketTypeId' => 'vip',
                        'quantity' => 2,
                    ],
                    [
                        'ticketTypeId' => 'general',
                        'quantity' => 3,
                    ],
                ],
            ]
        );

        $this->assertSame(
            201,
            $result['code'],
            json_encode($result)
        );

        $this->reservationId = $result['reservation']['id'];
    }

    /**
     * Preparar la seleccion del comprador.
     */
    private function selection(): array
    {
        return [
            'reservationId' => $this->reservationId,
            'items' => [
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 2,
                ],
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 3,
                ],
            ],
        ];
    }

    /**
     * Enviar una solicitud al controlador real.
     */
    private function createOrder(
        ?array $selection = null,
        ?string $key = null,
        string $userId = 'comprador-prueba'
    ): JsonResponse {
        $key ??= $this->idempotencyKey;

        $orderId = hash(
            'sha256',
            $userId . ':' . $key
        );

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

        return (new OrderController())->store(
            $request,
            $this->eventId,
            $this->firestore,
            new OrderSelectionValidator(),
            new OrderReservationValidator(),
            new OrderAmountCalculator()
        );
    }

    /**
     * Consultar el inventario de una categoria.
     */
    private function inventory(string $typeId): array
    {
        return $this->ticketDocuments[$typeId]
            ->snapshot()
            ->data();
    }

    /**
     * Consultar la reserva actual.
     */
    private function reservation(): array
    {
        return $this->eventDocument
            ->collection('reservations')
            ->document($this->reservationId)
            ->snapshot()
            ->data();
    }

    /**
     * Crear un pedido con VIP y General.
     */
    public function test_creates_multiple_order_and_links_reservation(): void
    {
        $response = $this->createOrder();

        $this->assertSame(
            201,
            $response->getStatusCode(),
            $response->getContent()
        );

        $body = $response->getData(true);

        $this->assertFalse($body['idempotent']);

        $order = $body['order'];

        $this->assertSame('pending', $order['status']);
        $this->assertSame(5, $order['quantity']);
        $this->assertSame('MXN', $order['currency']);

        $this->assertSame(450000, $order['subtotal']);
        $this->assertSame(0, $order['fees']);
        $this->assertSame(450000, $order['total']);

        $this->assertSame(
            'vendedor-prueba',
            $order['sellerId']
        );

        $this->assertSame(
            $this->reservationId,
            $order['reservationId']
        );

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
        ], $order['items']);

        $orderSnapshot = $this->firestore
            ->collection('orders')
            ->document($order['id'])
            ->snapshot();

        $this->assertTrue($orderSnapshot->exists());

        $this->assertSame(
            $order['id'],
            $this->reservation()['orderId']
        );

        // Crear el pedido no reserva inventario de nuevo.
        $this->assertSame(
            2,
            $this->inventory('vip')['reserved']
        );

        $this->assertSame(
            3,
            $this->inventory('general')['reserved']
        );

        $this->assertSame(
            0,
            $this->inventory('vip')['sold']
        );

        $this->assertSame(
            0,
            $this->inventory('general')['sold']
        );
    }

    /**
     * La misma clave debe devolver el mismo pedido.
     */
    public function test_same_key_returns_same_multiple_order(): void
    {
        $first = $this->createOrder();
        $second = $this->createOrder();

        $this->assertSame(
            201,
            $first->getStatusCode(),
            $first->getContent()
        );

        $this->assertSame(
            200,
            $second->getStatusCode(),
            $second->getContent()
        );

        $this->assertTrue(
            $second->getData(true)['idempotent']
        );

        $this->assertSame(
            $first->getData(true)['order']['id'],
            $second->getData(true)['order']['id']
        );

        $this->assertSame(
            2,
            $this->inventory('vip')['reserved']
        );

        $this->assertSame(
            3,
            $this->inventory('general')['reserved']
        );
    }

    /**
     * Una reserva no debe generar un segundo pedido.
     */
    public function test_same_reservation_cannot_create_second_order(): void
    {
        $first = $this->createOrder();

        $second = $this->createOrder(
            key: 'otra-clave-'
                . bin2hex(random_bytes(8))
        );

        $this->assertSame(
            201,
            $first->getStatusCode()
        );

        $this->assertSame(
            409,
            $second->getStatusCode()
        );

        $this->assertSame(
            2,
            $this->inventory('vip')['reserved']
        );

        $this->assertSame(
            3,
            $this->inventory('general')['reserved']
        );
    }

    /**
     * Rechazar categorias diferentes a las reservadas.
     */
    public function test_rejects_different_ticket_quantities(): void
    {
        $selection = $this->selection();

        $selection['items'][0]['quantity'] = 3;
        $selection['items'][1]['quantity'] = 2;

        $response = $this->createOrder($selection);

        $this->assertSame(
            409,
            $response->getStatusCode(),
            $response->getContent()
        );

        $this->assertArrayNotHasKey(
            'orderId',
            $this->reservation()
        );
    }

    /**
     * Otro comprador no puede utilizar la reserva.
     */
    public function test_rejects_another_buyer(): void
    {
        $response = $this->createOrder(
            userId: 'otro-comprador'
        );

        $this->assertSame(
            403,
            $response->getStatusCode(),
            $response->getContent()
        );

        $this->assertArrayNotHasKey(
            'orderId',
            $this->reservation()
        );
    }

    /**
     * No aceptar precios indicados por el comprador.
     */
    public function test_rejects_buyer_supplied_price(): void
    {
        $selection = $this->selection();

        $selection['items'][0]['unitPrice'] = 1;

        $response = $this->createOrder($selection);

        $this->assertSame(
            422,
            $response->getStatusCode(),
            $response->getContent()
        );

        $this->assertArrayNotHasKey(
            'orderId',
            $this->reservation()
        );
    }

    /**
     * Eliminar solo los documentos de estas pruebas.
     */
    protected function tearDown(): void
    {
        try {
            foreach ($this->orderDocuments as $document) {
                $document->delete();
            }

            if (
                isset(
                    $this->eventDocument,
                    $this->reservationId
                )
            ) {
                $this->eventDocument
                    ->collection('reservations')
                    ->document($this->reservationId)
                    ->delete();
            }

            foreach ($this->ticketDocuments as $document) {
                $document->delete();
            }

            if (isset($this->eventDocument)) {
                $this->eventDocument->delete();

                $this->firestore
                    ->collection('users')
                    ->document('comprador-prueba')
                    ->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
