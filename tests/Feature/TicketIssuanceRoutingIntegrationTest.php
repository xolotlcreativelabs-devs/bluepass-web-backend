<?php

namespace Tests\Feature;

use App\Services\PurchaseEmailOutboxService;
use App\Services\PurchaseNotificationService;
use App\Services\TicketIdGenerator;
use App\Services\TicketInventorySettlement;
use App\Services\TicketIssuanceService;
use App\Services\TicketIssuanceValidator;
use App\Services\TicketQrToken;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use RuntimeException;
use Tests\TestCase;

class TicketIssuanceRoutingIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $eventId;
    private string $orderId;
    private string $reservationId;
    private string $paymentReference;

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutar exclusivamente con el emulador.
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

        $this->eventId = 'test-routing-' . $suffix;
        $this->orderId = 'test-routing-order-' . $suffix;
        $this->reservationId = 'test-routing-res-' . $suffix;
        $this->paymentReference = 'test-routing-pay-' . $suffix;

        $now = new DateTimeImmutable();

        $event = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $event->set([
            'name' => 'Prueba de emision unificada',
            'status' => 'published',
            'createdAt' => new Timestamp($now),
        ]);

        foreach (['general', 'vip'] as $typeId) {
            $event
                ->collection('ticketTypes')
                ->document($typeId)
                ->set([
                    'status' => 'active',
                    'capacity' => 20,
                    'sold' => 0,
                    'reserved' => 0,
                    'updatedAt' => new Timestamp($now),
                ]);
        }
    }

    /**
     * Preparar un pedido tradicional o multiple.
     */
    private function prepareOrder(bool $multiple): void
    {
        $now = new DateTimeImmutable();
        $timestamp = new Timestamp($now);

        $event = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        if ($multiple) {
            $items = [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 3,
                    'unitPrice' => 50000,
                    'currency' => 'MXN',
                ],
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 2,
                    'unitPrice' => 150000,
                    'currency' => 'MXN',
                ],
            ];

            $orderItems = [
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
            ];

            $quantity = 5;
            $subtotal = 450000;

            $event->collection('ticketTypes')
                ->document('general')
                ->update([
                    [
                        'path' => 'reserved',
                        'value' => 3,
                    ],
                ]);

            $event->collection('ticketTypes')
                ->document('vip')
                ->update([
                    [
                        'path' => 'reserved',
                        'value' => 2,
                    ],
                ]);
        } else {
            $items = null;

            $orderItems = [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 2,
                    'unitPrice' => 50000,
                    'subtotal' => 100000,
                ],
            ];

            $quantity = 2;
            $subtotal = 100000;

            $event->collection('ticketTypes')
                ->document('general')
                ->update([
                    [
                        'path' => 'reserved',
                        'value' => 2,
                    ],
                ]);
        }

        $reservation = [
            'orderId' => $this->orderId,
            'userId' => 'comprador-prueba',
            'eventId' => $this->eventId,
            'status' => 'active',
            'quantity' => $quantity,
            'expiresAt' => new Timestamp(
                $now->modify('+10 minutes')
            ),
            'createdAt' => $timestamp,
        ];

        $order = [
            'userId' => 'comprador-prueba',
            'eventId' => $this->eventId,
            'reservationId' => $this->reservationId,
            'status' => 'pending',
            'quantity' => $quantity,
            'items' => $orderItems,
            'createdAt' => $timestamp,
        ];

        if ($multiple) {
            $reservation['items'] = $items;
            $reservation['currency'] = 'MXN';

            $order['currency'] = 'MXN';
            $order['subtotal'] = $subtotal;
            $order['fees'] = 0;
            $order['total'] = $subtotal;
        } else {
            $reservation['ticketTypeId'] = 'general';
            $reservation['unitPrice'] = 50000;

            $order['ticketTypeId'] = 'general';
            $order['unitPrice'] = 50000;
        }

        $event->collection('reservations')
            ->document($this->reservationId)
            ->set($reservation);

        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->set($order);
    }

    /**
     * Construir el punto de entrada principal.
     */
    private function service(): TicketIssuanceService
    {
        $notifications = $this->createMock(
            PurchaseNotificationService::class
        );

        $outbox = $this->createMock(
            PurchaseEmailOutboxService::class
        );

        return new TicketIssuanceService(
            $this->firestore,
            new TicketIssuanceValidator(),
            app(TicketIdGenerator::class),
            app(TicketInventorySettlement::class),
            app(TicketQrToken::class),
            $notifications,
            $outbox
        );
    }

    /**
     * Consultar el inventario actual.
     */
    private function inventory(string $typeId): array
    {
        return $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('ticketTypes')
            ->document($typeId)
            ->snapshot()
            ->data();
    }

    /**
     * El servicio principal conserva la emision tradicional.
     */
    public function test_routes_traditional_order_correctly(): void
    {
        $this->prepareOrder(false);

        $result = $this->service()
            ->issueForVerifiedPayment(
                $this->orderId,
                $this->paymentReference
            );

        $this->assertSame('paid', $result['status']);
        $this->assertFalse($result['alreadyIssued']);
        $this->assertCount(2, $result['ticketIds']);

        $this->assertSame(
            2,
            $this->inventory('general')['sold']
        );

        $this->assertSame(
            0,
            $this->inventory('general')['reserved']
        );

        foreach ($result['ticketIds'] as $ticketId) {
            $ticket = $this->firestore
                ->collection('tickets')
                ->document($ticketId)
                ->snapshot()
                ->data();

            $this->assertSame(
                'general',
                $ticket['ticketTypeId']
            );
        }
    }

    /**
     * El servicio principal reconoce varias categorias.
     */
    public function test_routes_multiple_order_correctly(): void
    {
        $this->prepareOrder(true);

        $service = $this->service();

        $first = $service->issueForVerifiedPayment(
            $this->orderId,
            $this->paymentReference
        );

        $second = $service->issueForVerifiedPayment(
            $this->orderId,
            $this->paymentReference
        );

        $this->assertSame('paid', $first['status']);
        $this->assertFalse($first['alreadyIssued']);
        $this->assertTrue($second['alreadyIssued']);

        $this->assertCount(5, $first['ticketIds']);

        $this->assertSame(
            $first['ticketIds'],
            $second['ticketIds']
        );

        $categories = [
            'general' => 0,
            'vip' => 0,
        ];

        foreach ($first['ticketIds'] as $ticketId) {
            $ticket = $this->firestore
                ->collection('tickets')
                ->document($ticketId)
                ->snapshot()
                ->data();

            $this->assertArrayHasKey(
                $ticket['ticketTypeId'],
                $categories
            );

            $categories[$ticket['ticketTypeId']]++;
        }

        $this->assertSame([
            'general' => 3,
            'vip' => 2,
        ], $categories);

        $this->assertSame(
            3,
            $this->inventory('general')['sold']
        );

        $this->assertSame(
            2,
            $this->inventory('vip')['sold']
        );

        $this->assertSame(
            0,
            $this->inventory('general')['reserved']
        );

        $this->assertSame(
            0,
            $this->inventory('vip')['reserved']
        );
    }

    /**
     * No permitir formatos tradicionales contradictorios.
     */
    public function test_rejects_inconsistent_order_format(): void
    {
        $this->prepareOrder(false);

        $orderDocument = $this->firestore
            ->collection('orders')
            ->document($this->orderId);

        $orderDocument->update([
            [
                'path' => 'items',
                'value' => [
                    [
                        'ticketTypeId' => 'vip',
                        'quantity' => 2,
                        'unitPrice' => 150000,
                        'subtotal' => 300000,
                    ],
                ],
            ],
        ]);

        try {
            $this->service()->issueForVerifiedPayment(
                $this->orderId,
                $this->paymentReference
            );

            $this->fail(
                'No se debe emitir un pedido inconsistente.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'El pedido contiene formatos de boletos inconsistentes.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            0,
            $this->inventory('general')['sold']
        );

        $this->assertSame(
            2,
            $this->inventory('general')['reserved']
        );

        $this->assertFalse(
            $this->firestore
                ->collection('paymentReferences')
                ->document(
                    hash('sha256', $this->paymentReference)
                )
                ->snapshot()
                ->exists()
        );
    }

    /**
     * Eliminar solo los datos creados por estas pruebas.
     */
    protected function tearDown(): void
    {
        try {
            if (
                isset(
                    $this->firestore,
                    $this->orderId
                )
            ) {
                $ticketIds = app(TicketIdGenerator::class)
                    ->generateForOrder($this->orderId, 5);

                foreach ($ticketIds as $ticketId) {
                    $this->firestore
                        ->collection('tickets')
                        ->document($ticketId)
                        ->delete();
                }

                $this->firestore
                    ->collection('paymentReferences')
                    ->document(
                        hash('sha256', $this->paymentReference)
                    )
                    ->delete();

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
                    $event->collection('reservations')
                        ->document($this->reservationId)
                        ->delete();
                }

                foreach (['general', 'vip'] as $typeId) {
                    $event->collection('ticketTypes')
                        ->document($typeId)
                        ->delete();
                }

                $event->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
