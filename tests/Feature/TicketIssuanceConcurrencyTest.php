<?php

namespace Tests\Feature;

use App\Services\TicketIdGenerator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TicketIssuanceConcurrencyTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $eventDocument;

    private DocumentReference $inventoryDocument;

    private string $eventId;

    private string $paymentReference;

    private array $orderIds = [];

    private array $reservationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Estas pruebas solo pueden utilizar Firestore Emulator.
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

        $this->eventId = 'test-issue-concurrent-' . $suffix;
        $this->paymentReference = 'payment-concurrent-' . $suffix;

        $this->orderIds = [
            'test-order-a-' . $suffix,
            'test-order-b-' . $suffix,
        ];

        $this->reservationIds = [
            'test-reservation-a-' . $suffix,
            'test-reservation-b-' . $suffix,
        ];

        $now = new DateTimeImmutable();
        $timestamp = new Timestamp($now);

        $this->eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $this->inventoryDocument = $this->eventDocument
            ->collection('ticketTypes')
            ->document('general');

        $this->eventDocument->set([
            'status' => 'published',
            'createdAt' => $timestamp,
        ]);

        $this->inventoryDocument->set([
            'status' => 'active',
            'capacity' => 20,
            'sold' => 1,
            'reserved' => 4,
            'updatedAt' => $timestamp,
        ]);

        // Preparar dos pedidos con reservas vigentes.
        foreach ($this->orderIds as $index => $orderId) {
            $reservationId = $this->reservationIds[$index];
            $userId = 'test-customer-' . $index . '-' . $suffix;

            $this->eventDocument
                ->collection('reservations')
                ->document($reservationId)
                ->set([
                    'orderId' => $orderId,
                    'userId' => $userId,
                    'eventId' => $this->eventId,
                    'ticketTypeId' => 'general',
                    'quantity' => 2,
                    'status' => 'active',
                    'expiresAt' => new Timestamp(
                        $now->modify('+15 minutes')
                    ),
                    'createdAt' => $timestamp,
                ]);

            $this->firestore
                ->collection('orders')
                ->document($orderId)
                ->set([
                    'userId' => $userId,
                    'eventId' => $this->eventId,
                    'ticketTypeId' => 'general',
                    'reservationId' => $reservationId,
                    'quantity' => 2,
                    'status' => 'pending',
                    'createdAt' => $timestamp,
                ]);
        }
    }

    /**
     * Crear un proceso independiente para confirmar el pago.
     */
    private function worker(string $orderId): Process
    {
        $process = new Process(
            [
                PHP_BINARY,
                base_path(
                    'tests/Support/TicketIssuanceConcurrencyWorker.php'
                ),
                $orderId,
                $this->paymentReference,
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
     * Ejecutar dos procesos y conservar los errores.
     */
    private function runTogether(
        string $firstOrderId,
        string $secondOrderId
    ): array {
        $first = $this->worker($firstOrderId);
        $second = $this->worker($secondOrderId);

        $first->start();
        $second->start();

        $first->wait();
        $second->wait();

        $results = [];

        foreach ([$first, $second] as $index => $process) {
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
                'El proceso ' . ($index + 1)
                    . ' no devolvio ninguna respuesta.'
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

            $results[] = [
                'process' => $index + 1,
                'success' => $result['success'],
                'orderId' => $result['orderId'] ?? null,
                'status' => $result['status'] ?? null,
                'alreadyIssued' => $result['alreadyIssued'] ?? null,
                'ticketIds' => $result['ticketIds'] ?? [],
                'errorClass' => $result['errorClass'] ?? null,
                'message' => $result['message'] ?? null,
                'stderr' => $errors,
            ];
        }

        return $results;
    }

    /**
     * Mostrar los resultados cuando una prueba falle.
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
     * Consultar un pedido.
     */
    private function getOrder(string $orderId): array
    {
        return $this->firestore
            ->collection('orders')
            ->document($orderId)
            ->snapshot()
            ->data();
    }

    /**
     * Consultar el inventario.
     */
    private function getInventory(): array
    {
        return $this->inventoryDocument
            ->snapshot()
            ->data();
    }

    /**
     * Verificar si existen los boletos del pedido.
     */
    private function assertTickets(
        string $orderId,
        bool $issued
    ): void {
        $ticketIds = app(TicketIdGenerator::class)
            ->generateForOrder($orderId, 2);

        foreach ($ticketIds as $ticketId) {
            $ticket = $this->firestore
                ->collection('tickets')
                ->document($ticketId)
                ->snapshot();

            $this->assertSame(
                $issued,
                $ticket->exists()
            );

            if ($issued) {
                $this->assertSame(
                    $orderId,
                    $ticket->data()['orderId']
                );
            }
        }
    }

    /**
     * Dos confirmaciones del mismo pedido no duplican boletos.
     */
    public function test_same_order_does_not_duplicate_tickets(): void
    {
        $orderId = $this->orderIds[0];

        $results = $this->runTogether(
            $orderId,
            $orderId
        );

        $successful = array_values(
            array_filter(
                $results,
                fn (array $result): bool =>
                    $result['success'] === true
            )
        );

        $this->assertNotEmpty(
            $successful,
            'Ninguna confirmacion pudo completarse.'
                . "\nResultados de ambos procesos:\n"
                . $this->describeResults($results)
        );

        foreach ($successful as $result) {
            $this->assertSame('paid', $result['status']);
            $this->assertCount(2, $result['ticketIds']);
        }

        $order = $this->getOrder($orderId);

        $this->assertSame('paid', $order['status']);
        $this->assertCount(2, $order['issuedTicketIds']);

        $inventory = $this->getInventory();

        $this->assertSame(3, $inventory['sold']);
        $this->assertSame(2, $inventory['reserved']);

        $this->assertTickets($orderId, true);
        $this->assertTickets($this->orderIds[1], false);

        $reservation = $this->eventDocument
            ->collection('reservations')
            ->document($this->reservationIds[0])
            ->snapshot()
            ->data();

        $this->assertSame(
            'confirmed',
            $reservation['status']
        );

        $this->assertSame(
            'pending',
            $this->getOrder($this->orderIds[1])['status']
        );
    }

    /**
     * Dos pedidos no pueden compartir una referencia de pago.
     */
    public function test_different_orders_cannot_share_payment(): void
    {
        $results = $this->runTogether(
            $this->orderIds[0],
            $this->orderIds[1]
        );

        $successful = array_values(
            array_filter(
                $results,
                fn (array $result): bool =>
                    $result['success'] === true
            )
        );

        $this->assertCount(
            1,
            $successful,
            'Una referencia solo puede pagar un pedido.'
                . "\nResultados de ambos procesos:\n"
                . $this->describeResults($results)
        );

        $paidOrderId = $successful[0]['orderId'];

        $this->assertContains(
            $paidOrderId,
            $this->orderIds
        );

        foreach ($this->orderIds as $orderId) {
            $order = $this->getOrder($orderId);

            if ($orderId === $paidOrderId) {
                $this->assertSame('paid', $order['status']);
                $this->assertTickets($orderId, true);
            } else {
                $this->assertSame('pending', $order['status']);
                $this->assertTickets($orderId, false);
            }
        }

        $inventory = $this->getInventory();

        $this->assertSame(3, $inventory['sold']);
        $this->assertSame(2, $inventory['reserved']);

        $paymentId = hash(
            'sha256',
            $this->paymentReference
        );

        $payment = $this->firestore
            ->collection('paymentReferences')
            ->document($paymentId)
            ->snapshot();

        $this->assertTrue($payment->exists());

        $this->assertSame(
            $paidOrderId,
            $payment->data()['orderId']
        );
    }

    /**
     * Eliminar los documentos temporales.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                $generator = app(TicketIdGenerator::class);

                foreach ($this->orderIds as $orderId) {
                    foreach (
                        $generator->generateForOrder($orderId, 2)
                        as $ticketId
                    ) {
                        $this->firestore
                            ->collection('tickets')
                            ->document($ticketId)
                            ->delete();
                    }

                    $this->firestore
                        ->collection('orders')
                        ->document($orderId)
                        ->delete();
                }

                if (isset($this->paymentReference)) {
                    $this->firestore
                        ->collection('paymentReferences')
                        ->document(
                            hash('sha256', $this->paymentReference)
                        )
                        ->delete();
                }

                if (isset($this->eventDocument)) {
                    foreach (
                        $this->reservationIds as $reservationId
                    ) {
                        $this->eventDocument
                            ->collection('reservations')
                            ->document($reservationId)
                            ->delete();
                    }

                    $this->inventoryDocument->delete();
                    $this->eventDocument->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
