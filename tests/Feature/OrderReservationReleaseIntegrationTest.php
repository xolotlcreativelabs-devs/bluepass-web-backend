<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketReservationReleaseController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderReservationReleaseIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $eventDocument;

    private DocumentReference $ticketDocument;

    private DocumentReference $reservationDocument;

    private DocumentReference $orderDocument;

    private string $eventId;

    private string $reservationId;

    private string $orderId;

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutar las pruebas solamente en Firestore Emulator.
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

        $this->eventId = 'evento-liberacion-' . $suffix;
        $this->reservationId = 'reserva-liberacion-' . $suffix;
        $this->orderId = 'pedido-liberacion-' . $suffix;

        $this->eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $this->ticketDocument = $this->eventDocument
            ->collection('ticketTypes')
            ->document('general');

        $this->reservationDocument = $this->eventDocument
            ->collection('reservations')
            ->document($this->reservationId);

        $this->orderDocument = $this->firestore
            ->collection('orders')
            ->document($this->orderId);

        $now = new DateTimeImmutable();
        $timestamp = new Timestamp($now);
        $expiresAt = new Timestamp($now->modify('+10 minutes'));

        $this->eventDocument->set([
            'name' => 'Evento de prueba de liberacion',
            'status' => 'published',
            'createdAt' => $timestamp,
        ]);

        $this->ticketDocument->set([
            'name' => 'General',
            'status' => 'active',
            'price' => 350,
            'currency' => 'MXN',
            'capacity' => 10,
            'sold' => 0,
            'reserved' => 2,
            'updatedAt' => $timestamp,
        ]);

        $this->reservationDocument->set([
            'eventId' => $this->eventId,
            'ticketTypeId' => 'general',
            'userId' => 'comprador-liberacion',
            'orderId' => $this->orderId,
            'quantity' => 2,
            'unitPrice' => 350,
            'currency' => 'MXN',
            'status' => 'active',
            'createdAt' => $timestamp,
            'updatedAt' => $timestamp,
            'expiresAt' => $expiresAt,
        ]);

        $this->orderDocument->set([
            'userId' => 'comprador-liberacion',
            'eventId' => $this->eventId,
            'reservationId' => $this->reservationId,
            'ticketTypeId' => 'general',
            'quantity' => 2,
            'unitPrice' => 350,
            'currency' => 'MXN',
            'subtotal' => 700,
            'fees' => 0,
            'total' => 700,
            'status' => 'pending',
            'expiresAt' => $expiresAt,
            'createdAt' => $timestamp,
            'updatedAt' => $timestamp,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->orderDocument)) {
                $this->orderDocument->delete();
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
            parent::tearDown();
        }
    }

    /**
     * Cancelar una reserva mediante el controlador real.
     */
    private function releaseReservation(): JsonResponse
    {
        $request = Request::create(
            '/api/events/' . $this->eventId .
                '/reservations/' . $this->reservationId,
            'DELETE'
        );

        $request->attributes->set(
            'firebase_uid',
            'comprador-liberacion'
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        return (new TicketReservationReleaseController())->destroy(
            $request,
            $this->eventId,
            $this->reservationId,
            $this->firestore
        );
    }

    public function test_cancels_reservation_and_order(): void
    {
        $response = $this->releaseReservation();

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertTrue($body['released']);
        $this->assertSame('cancelled', $body['status']);
        $this->assertSame($this->orderId, $body['orderId']);

        $reservation = $this->reservationDocument
            ->snapshot()
            ->data();

        $order = $this->orderDocument
            ->snapshot()
            ->data();

        $inventory = $this->ticketDocument
            ->snapshot()
            ->data();

        $this->assertSame('cancelled', $reservation['status']);
        $this->assertSame('cancelled', $order['status']);
        $this->assertSame(0, $inventory['reserved']);
        $this->assertSame(10, $inventory['capacity']);
        $this->assertSame(0, $inventory['sold']);

        // Repetir la cancelacion no debe liberar mas boletos.
        $secondResponse = $this->releaseReservation();
        $secondBody = $secondResponse->getData(true);

        $this->assertSame(200, $secondResponse->getStatusCode());
        $this->assertFalse($secondBody['released']);

        $inventoryAfterRetry = $this->ticketDocument
            ->snapshot()
            ->data();

        $this->assertSame(0, $inventoryAfterRetry['reserved']);
    }

    public function test_expires_reservation_and_order(): void
    {
        // Simular una reserva cuyo vencimiento ya paso.
        $this->reservationDocument->update([
            [
                'path' => 'expiresAt',
                'value' => new Timestamp(
                    new DateTimeImmutable('-1 hour')
                ),
            ],
        ]);

        $response = $this->releaseReservation();

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertTrue($body['released']);
        $this->assertSame('expired', $body['status']);

        $reservation = $this->reservationDocument
            ->snapshot()
            ->data();

        $order = $this->orderDocument
            ->snapshot()
            ->data();

        $inventory = $this->ticketDocument
            ->snapshot()
            ->data();

        $this->assertSame('expired', $reservation['status']);
        $this->assertSame('expired', $order['status']);
        $this->assertSame(0, $inventory['reserved']);
    }

    public function test_does_not_release_paid_order(): void
    {
        // Simular un pedido ya pagado.
        $this->orderDocument->update([
            [
                'path' => 'status',
                'value' => 'paid',
            ],
        ]);

        $response = $this->releaseReservation();

        $this->assertSame(409, $response->getStatusCode());

        $reservation = $this->reservationDocument
            ->snapshot()
            ->data();

        $order = $this->orderDocument
            ->snapshot()
            ->data();

        $inventory = $this->ticketDocument
            ->snapshot()
            ->data();

        $this->assertSame('active', $reservation['status']);
        $this->assertSame('paid', $order['status']);
        $this->assertSame(2, $inventory['reserved']);
    }
}
