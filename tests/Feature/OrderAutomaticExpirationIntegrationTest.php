<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Console\Command;
use Tests\TestCase;

class OrderAutomaticExpirationIntegrationTest extends TestCase
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

        // Nunca ejecutar estas pruebas contra Firebase real.
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

        // Laravel debe utilizar el mismo cliente del emulador.
        $this->app->instance(
            FirestoreClient::class,
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'evento-auto-' . $suffix;
        $this->reservationId = 'reserva-auto-' . $suffix;
        $this->orderId = 'pedido-auto-' . $suffix;

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
        $expiresAt = new Timestamp(
            $now->modify('-1 hour')
        );

        $this->eventDocument->set([
            'name' => 'Evento temporal de vencimiento',
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
            'userId' => 'comprador-auto',
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
            'userId' => 'comprador-auto',
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
     * Ejecutar el comando real utilizando Firestore Emulator.
     */
    private function expireReservations(): int
    {
        return $this->artisan('reservations:expire')->run();
    }

    public function test_expires_reservation_and_linked_order(): void
    {
        $exitCode = $this->expireReservations();

        $this->assertSame(Command::SUCCESS, $exitCode);

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
        $this->assertSame(10, $inventory['capacity']);
        $this->assertSame(0, $inventory['sold']);

        // Repetir el comando no debe liberar inventario otra vez.
        $secondExitCode = $this->expireReservations();

        $this->assertSame(Command::SUCCESS, $secondExitCode);

        $inventoryAfterRetry = $this->ticketDocument
            ->snapshot()
            ->data();

        $this->assertSame(
            0,
            $inventoryAfterRetry['reserved']
        );
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

        // El comando debe reportar el conflicto.
        $exitCode = $this->expireReservations();

        $this->assertSame(Command::FAILURE, $exitCode);

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

    public function test_expires_reservation_without_order(): void
    {
        // Simular una reserva antigua que no tiene pedido.
        $this->reservationDocument->update([
            [
                'path' => 'orderId',
                'value' => null,
            ],
        ]);

        $this->orderDocument->delete();

        $exitCode = $this->expireReservations();

        $this->assertSame(Command::SUCCESS, $exitCode);

        $reservation = $this->reservationDocument
            ->snapshot()
            ->data();

        $inventory = $this->ticketDocument
            ->snapshot()
            ->data();

        $this->assertSame('expired', $reservation['status']);
        $this->assertSame(0, $inventory['reserved']);

        $this->assertFalse(
            $this->orderDocument->snapshot()->exists()
        );
    }
}
