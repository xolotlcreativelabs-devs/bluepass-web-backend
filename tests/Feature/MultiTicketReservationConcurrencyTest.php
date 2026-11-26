<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MultiTicketReservationConcurrencyTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $eventDocument;

    private array $ticketDocuments = [];

    private string $eventId;

    protected function setUp(): void
    {
        parent::setUp();

        // Impedir conexiones a una base de datos real.
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

        $this->eventId = 'test-multi-concurrent-'
            . bin2hex(random_bytes(8));

        $now = new DateTimeImmutable();

        $this->eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $this->eventDocument->set([
            'name' => 'Evento de concurrencia BluePass',
            'status' => 'published',
            'date' => new Timestamp(
                $now->modify('+10 days')
            ),
        ]);

        // Solo hay inventario para una compra completa.
        $types = [
            'vip' => [
                'capacity' => 2,
                'price' => 1500,
            ],
            'general' => [
                'capacity' => 3,
                'price' => 500,
            ],
        ];

        foreach ($types as $typeId => $data) {
            $document = $this->eventDocument
                ->collection('ticketTypes')
                ->document($typeId);

            $document->set([
                'name' => $typeId,
                'status' => 'active',
                'price' => $data['price'],
                'capacity' => $data['capacity'],
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
     * Preparar un proceso PHP independiente.
     */
    private function worker(string $userId): Process
    {
        $process = new Process(
            [
                PHP_BINARY,
                base_path(
                    'tests/Support/MultiTicketReservationConcurrencyWorker.php'
                ),
                $this->eventId,
                $userId,
            ],
            base_path(),
            [
                'FIRESTORE_EMULATOR_HOST' => '127.0.0.1:8080',
                'GOOGLE_CLOUD_PROJECT' => 'demo-bluepass-hu04',
            ]
        );

        $process->setTimeout(60);

        return $process;
    }

    /**
     * Ejecutar simultaneamente dos solicitudes de reserva.
     */
    private function runTogether(): array
    {
        $processes = [
            $this->worker('comprador-concurrente-a'),
            $this->worker('comprador-concurrente-b'),
        ];

        // Iniciar ambos antes de esperar sus resultados.
        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->wait();
        }

        $results = [];

        foreach ($processes as $index => $process) {
            $output = trim($process->getOutput());
            $errors = trim($process->getErrorOutput());

            $this->assertTrue(
                $process->isSuccessful(),
                'El proceso ' . ($index + 1)
                    . ' termino incorrectamente.'
                    . "\nSalida: " . $output
                    . "\nErrores: " . $errors
            );

            $this->assertNotSame(
                '',
                $output,
                'El proceso no devolvio una respuesta.'
                    . "\nErrores: " . $errors
            );

            $result = json_decode(
                $output,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Presentar los resultados cuando falle una prueba.
     */
    private function describeResults(array $results): string
    {
        return json_encode(
            $results,
            JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
        );
    }

    /**
     * Impedir que dos compradores reserven los ultimos boletos.
     */
    public function test_two_buyers_cannot_reserve_the_same_tickets(): void
    {
        $results = $this->runTogether();

        $successful = array_values(
            array_filter(
                $results,
                static fn (array $result): bool =>
                    $result['success'] === true
            )
        );

        $this->assertCount(
            1,
            $successful,
            'Solo una reserva debe completarse.'
                . "\nResultados:\n"
                . $this->describeResults($results)
        );

        $winner = $successful[0];

        $this->assertSame(201, $winner['code']);
        $this->assertNotEmpty($winner['reservationId']);

        $reservations = [];

        foreach (
            $this->eventDocument
                ->collection('reservations')
                ->documents() as $snapshot
        ) {
            if ($snapshot->exists()) {
                $reservations[$snapshot->id()] = $snapshot->data();
            }
        }

        // Solo debe existir una reserva.
        $this->assertCount(1, $reservations);

        $reservationId = $winner['reservationId'];

        $this->assertArrayHasKey(
            $reservationId,
            $reservations
        );

        $reservation = $reservations[$reservationId];

        $this->assertSame(
            $winner['userId'],
            $reservation['userId']
        );

        $this->assertSame(
            'active',
            $reservation['status']
        );

        $this->assertSame(5, $reservation['quantity']);
        $this->assertCount(2, $reservation['items']);

        $quantities = [];

        foreach ($reservation['items'] as $item) {
            $quantities[$item['ticketTypeId']] =
                $item['quantity'];
        }

        $this->assertSame(2, $quantities['vip']);
        $this->assertSame(3, $quantities['general']);

        $vip = $this->ticketDocuments['vip']
            ->snapshot()
            ->data();

        $general = $this->ticketDocuments['general']
            ->snapshot()
            ->data();

        // El inventario nunca puede superar su capacidad.
        $this->assertSame(2, $vip['capacity']);
        $this->assertSame(2, $vip['reserved']);
        $this->assertSame(0, $vip['sold']);

        $this->assertSame(3, $general['capacity']);
        $this->assertSame(3, $general['reserved']);
        $this->assertSame(0, $general['sold']);

        $this->assertLessThanOrEqual(
            $vip['capacity'],
            $vip['sold'] + $vip['reserved']
        );

        $this->assertLessThanOrEqual(
            $general['capacity'],
            $general['sold'] + $general['reserved']
        );
    }

    /**
     * Eliminar exclusivamente los documentos de esta prueba.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->eventDocument)) {
                foreach (
                    $this->eventDocument
                        ->collection('reservations')
                        ->documents() as $reservation
                ) {
                    if ($reservation->exists()) {
                        $reservation->reference()->delete();
                    }
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
