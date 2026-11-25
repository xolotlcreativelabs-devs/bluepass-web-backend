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

class OrderSellerValidationTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $eventId;
    private string $reservationId;
    private string $userId;
    private string $orderId;

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutar solamente contra Firestore Emulator.
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

        $this->eventId = 'evento-sin-vendedor-' . $suffix;
        $this->reservationId = 'reserva-sin-vendedor-' . $suffix;
        $this->userId = 'comprador-vendedor-' . $suffix;

        $key = 'clave-sin-vendedor-' . $suffix;

        $this->orderId = hash(
            'sha256',
            $this->userId . ':' . $key
        );

        $now = new DateTimeImmutable();
        $timestamp = new Timestamp($now);

        $event = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        // Evento publicado sin vendedor: dato invalido.
        $event->set([
            'name' => 'Evento sin vendedor',
            'status' => 'published',
            'createdAt' => $timestamp,
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

        $this->key = $key;
    }

    private string $key;

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

    public function test_rejects_order_without_seller(): void
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
                'name' => 'Comprador de prueba',
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

        // El evento no tiene vendedor: no debe generar pedido.
        $this->assertSame(
            409,
            $response->getStatusCode()
        );

        $orderExists = $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->snapshot()
            ->exists();

        $this->assertFalse($orderExists);

        $reservation = $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reservations')
            ->document($this->reservationId)
            ->snapshot()
            ->data();

        $this->assertArrayNotHasKey(
            'orderId',
            $reservation
        );
    }
}
