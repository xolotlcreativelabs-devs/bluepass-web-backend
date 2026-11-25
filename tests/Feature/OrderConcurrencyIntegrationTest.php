<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OrderConcurrencyIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $eventDocument;

    private DocumentReference $ticketDocument;

    private DocumentReference $reservationDocument;

    private array $orderDocuments = [];

    private string $eventId;

    private string $reservationId;

    private string $userId;

    private string $sellerId;

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

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'evento-concurrencia-' . $suffix;
        $this->reservationId = 'reserva-concurrencia-' . $suffix;
        $this->userId = 'comprador-concurrencia-' . $suffix;
        $this->sellerId = 'vendedor-concurrencia-' . $suffix;

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
        $timestamp = new Timestamp($now);

        // Un evento publicado debe tener vendedor.
        $this->eventDocument->set([
            'sellerId' => $this->sellerId,
            'name' => 'Evento de concurrencia',
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
            'createdAt' => $timestamp,
            'updatedAt' => $timestamp,
        ]);

        $this->reservationDocument->set([
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
            parent::tearDown();
        }
    }

    /**
     * Preparar un proceso PHP para crear el pedido.
     */
    private function worker(string $key): Process
    {
        $orderId = hash(
            'sha256',
            $this->userId . ':' . $key
        );

        $this->orderDocuments[$orderId] = $this->firestore
            ->collection('orders')
            ->document($orderId);

        $process = new Process(
            [
                PHP_BINARY,
                base_path(
                    'tests/Support/OrderConcurrencyWorker.php'
                ),
                $this->eventId,
                $this->reservationId,
                $key,
                $this->userId,
            ],
            base_path()
        );

        $process->setTimeout(60);

        return $process;
    }

    /**
     * Lanzar los dos procesos simultaneamente.
     */
    private function runTogether(
        string $firstKey,
        string $secondKey
    ): array {
        $first = $this->worker($firstKey);
        $second = $this->worker($secondKey);

        $first->start();
        $second->start();

        $first->wait();
        $second->wait();

        $results = [];

        foreach ([$first, $second] as $process) {
            $this->assertTrue(
                $process->isSuccessful(),
                'Fallo un proceso PHP: ' .
                $process->getErrorOutput() .
                $process->getOutput()
            );

            $output = trim($process->getOutput());

            $result = json_decode(
                $output,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $this->assertIsArray($result);
            $this->assertArrayHasKey('httpStatus', $result);

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Comprobar que el inventario reservado no cambio.
     */
    private function assertInventoryPreserved(): void
    {
        $inventory = $this->ticketDocument
            ->snapshot()
            ->data();

        $this->assertSame(10, $inventory['capacity']);
        $this->assertSame(0, $inventory['sold']);
        $this->assertSame(2, $inventory['reserved']);
    }

    /**
     * Comprobar que la reserva tiene un unico pedido.
     */
    private function assertReservationLinked(): string
    {
        $reservation = $this->reservationDocument
            ->snapshot()
            ->data();

        $this->assertSame('active', $reservation['status']);

        $orderId = $reservation['orderId'] ?? null;

        $this->assertIsString($orderId);
        $this->assertNotEmpty($orderId);

        $order = $this->firestore
            ->collection('orders')
            ->document($orderId)
            ->snapshot();

        $this->assertTrue($order->exists());

        $data = $order->data();

        $this->assertSame(
            $this->reservationId,
            $data['reservationId']
        );

        $this->assertSame(
            $this->userId,
            $data['userId']
        );

        $this->assertSame(
            $this->sellerId,
            $data['sellerId']
        );

        $this->assertSame(
            'online',
            $data['channel']
        );

        $this->assertSame(700, $data['total']);
        $this->assertSame('pending', $data['status']);

        return $orderId;
    }

    public function test_same_key_creates_only_one_order(): void
    {
        $key = 'misma-clave-' .
            bin2hex(random_bytes(8));

        $results = $this->runTogether($key, $key);

        $statuses = array_column(
            $results,
            'httpStatus'
        );

        sort($statuses);

        $this->assertSame(
            [200, 201],
            $statuses,
            'La misma clave debe crear un solo pedido.'
        );

        $expectedOrderId = hash(
            'sha256',
            $this->userId . ':' . $key
        );

        foreach ($results as $result) {
            $this->assertSame(
                $expectedOrderId,
                $result['orderId']
            );
        }

        $this->assertSame(
            $expectedOrderId,
            $this->assertReservationLinked()
        );

        $this->assertInventoryPreserved();
    }

    public function test_different_keys_cannot_reuse_reservation(): void
    {
        $firstKey = 'primera-clave-' .
            bin2hex(random_bytes(8));

        $secondKey = 'segunda-clave-' .
            bin2hex(random_bytes(8));

        $results = $this->runTogether(
            $firstKey,
            $secondKey
        );

        $statuses = array_column(
            $results,
            'httpStatus'
        );

        sort($statuses);

        $this->assertSame(
            [201, 409],
            $statuses,
            'Una reserva solo debe crear un pedido.'
        );

        $linkedOrderId = $this->assertReservationLinked();

        $createdOrders = 0;

        foreach ($this->orderDocuments as $orderId => $document) {
            if ($document->snapshot()->exists()) {
                $createdOrders++;

                $this->assertSame(
                    $linkedOrderId,
                    $orderId
                );
            }
        }

        $this->assertSame(1, $createdOrders);

        $this->assertInventoryPreserved();
    }
}
