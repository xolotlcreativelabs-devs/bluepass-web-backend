<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\TicketReservationController;
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

class MultiTicketPurchaseFlowIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $eventDocument;

    private string $eventId;

    private string $key;

    private array $ticketDocuments = [];

    private array $reservationIds = [];

    private array $orderIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutar solamente contra Firestore Emulator.
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

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'test-purchase-flow-' . $suffix;
        $this->key = 'clave-compra-' . $suffix;

        $this->eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $now = new DateTimeImmutable();

        $this->eventDocument->set([
            'name' => 'Evento de compra multiple',
            'sellerId' => 'vendedor-prueba',
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
    }

    /**
     * Seleccionar dos VIP y tres Generales.
     */
    private function items(): array
    {
        return [
            [
                'ticketTypeId' => 'vip',
                'quantity' => 2,
            ],
            [
                'ticketTypeId' => 'general',
                'quantity' => 3,
            ],
        ];
    }

    /**
     * Crear la reserva desde el controlador real.
     */
    private function reserve(
        ?array $data = null,
        string $userId = 'comprador-prueba'
    ): JsonResponse {
        $request = Request::create(
            '/api/events/' . $this->eventId . '/reservations',
            'POST',
            $data ?? [
                'items' => $this->items(),
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

        $response = (new TicketReservationController())
            ->store(
                $request,
                $this->eventId,
                $this->firestore
            );

        $body = $response->getData(true);

        if (
            $response->getStatusCode() === 201 &&
            isset($body['reservation']['id'])
        ) {
            $this->reservationIds[] =
                $body['reservation']['id'];
        }

        return $response;
    }

    /**
     * Crear el pedido desde el controlador real.
     */
    private function order(
        string $reservationId,
        ?array $items = null,
        ?string $key = null,
        string $userId = 'comprador-prueba'
    ): JsonResponse {
        $key ??= $this->key;

        $orderId = hash(
            'sha256',
            $userId . ':' . $key
        );

        $this->orderIds[] = $orderId;

        $request = Request::create(
            '/api/events/' . $this->eventId . '/orders',
            'POST',
            [
                'reservationId' => $reservationId,
                'items' => $items ?? $this->items(),
            ],
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
     * Consultar una reserva creada.
     */
    private function reservation(
        string $reservationId
    ): array {
        return $this->eventDocument
            ->collection('reservations')
            ->document($reservationId)
            ->snapshot()
            ->data();
    }

    /**
     * Crear reserva y pedido multiples completos.
     */
    public function test_creates_reservation_then_single_multiple_order(): void
    {
        $reserveResponse = $this->reserve();

        $this->assertSame(
            201,
            $reserveResponse->getStatusCode(),
            $reserveResponse->getContent()
        );

        $reservation = $reserveResponse
            ->getData(true)['reservation'];

        $this->assertSame(5, $reservation['quantity']);
        $this->assertCount(2, $reservation['items']);

        $this->assertSame(
            2,
            $this->inventory('vip')['reserved']
        );

        $this->assertSame(
            3,
            $this->inventory('general')['reserved']
        );

        $orderResponse = $this->order(
            $reservation['id']
        );

        $this->assertSame(
            201,
            $orderResponse->getStatusCode(),
            $orderResponse->getContent()
        );

        $order = $orderResponse
            ->getData(true)['order'];

        $this->assertSame('pending', $order['status']);
        $this->assertSame(5, $order['quantity']);
        $this->assertSame(450000, $order['subtotal']);
        $this->assertSame(450000, $order['total']);
        $this->assertSame('MXN', $order['currency']);

        $this->assertSame(
            $reservation['id'],
            $order['reservationId']
        );

        $this->assertCount(2, $order['items']);

        $this->assertSame(
            $order['id'],
            $this->reservation(
                $reservation['id']
            )['orderId']
        );

        // Crear el pedido no descuenta boletos nuevamente.
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
     * Repetir el pedido no genera otro documento.
     */
    public function test_reuses_same_order_without_reserving_again(): void
    {
        $reserveResponse = $this->reserve();

        $this->assertSame(
            201,
            $reserveResponse->getStatusCode()
        );

        $reservationId = $reserveResponse
            ->getData(true)['reservation']['id'];

        $first = $this->order($reservationId);
        $second = $this->order($reservationId);

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
     * No crear un pedido con cantidades diferentes.
     */
    public function test_rejects_order_that_differs_from_reservation(): void
    {
        $reserveResponse = $this->reserve();

        $this->assertSame(
            201,
            $reserveResponse->getStatusCode()
        );

        $reservationId = $reserveResponse
            ->getData(true)['reservation']['id'];

        $response = $this->order(
            $reservationId,
            [
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 3,
                ],
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 2,
                ],
            ]
        );

        $this->assertSame(
            409,
            $response->getStatusCode(),
            $response->getContent()
        );

        $this->assertArrayNotHasKey(
            'orderId',
            $this->reservation($reservationId)
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
     * Rechazar toda la reserva si falta inventario VIP.
     */
    public function test_rejects_entire_reservation_without_partial_stock(): void
    {
        $this->ticketDocuments['vip']->update([
            [
                'path' => 'reserved',
                'value' => 9,
            ],
        ]);

        $response = $this->reserve();

        $this->assertSame(
            409,
            $response->getStatusCode(),
            $response->getContent()
        );

        // No reservar boletos Generales si VIP falla.
        $this->assertSame(
            9,
            $this->inventory('vip')['reserved']
        );

        $this->assertSame(
            0,
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
     * Eliminar solo los documentos de las pruebas.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                foreach (array_unique($this->orderIds) as $orderId) {
                    $this->firestore
                        ->collection('orders')
                        ->document($orderId)
                        ->delete();
                }
            }

            if (isset($this->eventDocument)) {
                foreach (array_unique($this->reservationIds) as $id) {
                    $this->eventDocument
                        ->collection('reservations')
                        ->document($id)
                        ->delete();
                }

                foreach ($this->ticketDocuments as $document) {
                    $document->delete();
                }

                $this->eventDocument->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
