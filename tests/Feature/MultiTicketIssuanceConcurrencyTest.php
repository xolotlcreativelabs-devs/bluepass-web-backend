<?php

namespace Tests\Feature;

use App\Services\TicketIdGenerator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MultiTicketIssuanceConcurrencyTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $eventDocument;

    private string $eventId;
    private string $paymentReference;

    private array $orderIds = [];
    private array $reservationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Usar exclusivamente Firestore Emulator.
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

        $this->eventId = 'test-multi-concurrent-' . $suffix;
        $this->paymentReference = 'multi-payment-' . $suffix;

        $this->orderIds = [
            'multi-order-a-' . $suffix,
            'multi-order-b-' . $suffix,
        ];

        $this->reservationIds = [
            'multi-res-a-' . $suffix,
            'multi-res-b-' . $suffix,
        ];

        $now = new DateTimeImmutable();
        $timestamp = new Timestamp($now);

        $this->eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $this->eventDocument->set([
            'status' => 'published',
            'createdAt' => $timestamp,
        ]);

        // El inventario incluye las reservas de
        // los dos pedidos preparados para la prueba.
        $this->eventDocument
            ->collection('ticketTypes')
            ->document('general')
            ->set([
                'status' => 'active',
                'capacity' => 20,
                'sold' => 1,
                'reserved' => 6,
                'updatedAt' => $timestamp,
            ]);

        $this->eventDocument
            ->collection('ticketTypes')
            ->document('vip')
            ->set([
                'status' => 'active',
                'capacity' => 20,
                'sold' => 2,
                'reserved' => 4,
                'updatedAt' => $timestamp,
            ]);

        foreach ($this->orderIds as $index => $orderId) {
            $reservationId = $this->reservationIds[$index];
            $userId = 'multi-buyer-' . $index . '-' . $suffix;

            $reservationItems = [
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

            $this->eventDocument
                ->collection('reservations')
                ->document($reservationId)
                ->set([
                    'orderId' => $orderId,
                    'userId' => $userId,
                    'eventId' => $this->eventId,
                    'quantity' => 5,
                    'currency' => 'MXN',
                    'items' => $reservationItems,
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
                    'reservationId' => $reservationId,
                    'quantity' => 5,
                    'currency' => 'MXN',
                    'items' => $orderItems,
                    'subtotal' => 450000,
                    'fees' => 0,
                    'total' => 450000,
                    'status' => 'pending',
                    'createdAt' => $timestamp,
                ]);
        }
    }

    /**
     * Iniciar un proceso independiente.
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

        $process->setTimeout(90);

        return $process;
    }

    /**
     * Ejecutar dos confirmaciones simultaneas.
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
            $this->assertArrayHasKey(
                'success',
                $result
            );

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Mostrar informacion cuando una prueba falle.
     */
    private function describe(array $results): string
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
    private function order(string $orderId): array
    {
        return $this->firestore
            ->collection('orders')
            ->document($orderId)
            ->snapshot()
            ->data();
    }

    /**
     * Consultar el inventario de una categoria.
     */
    private function inventory(string $typeId): array
    {
        return $this->eventDocument
            ->collection('ticketTypes')
            ->document($typeId)
            ->snapshot()
            ->data();
    }

    /**
     * Consultar una reserva.
     */
    private function reservation(int $index): array
    {
        return $this->eventDocument
            ->collection('reservations')
            ->document($this->reservationIds[$index])
            ->snapshot()
            ->data();
    }

    /**
     * Comprobar los cinco boletos de un pedido.
     */
    private function assertTickets(
        string $orderId,
        bool $issued
    ): void {
        $ticketIds = app(TicketIdGenerator::class)
            ->generateForOrder($orderId, 5);

        $categories = [
            'general' => 0,
            'vip' => 0,
        ];

        foreach ($ticketIds as $position => $ticketId) {
            $snapshot = $this->firestore
                ->collection('tickets')
                ->document($ticketId)
                ->snapshot();

            $this->assertSame(
                $issued,
                $snapshot->exists()
            );

            if (!$issued) {
                continue;
            }

            $ticket = $snapshot->data();

            $this->assertSame(
                $orderId,
                $ticket['orderId']
            );

            $this->assertSame(
                $position + 1,
                $ticket['position']
            );

            $this->assertSame(
                'valid',
                $ticket['status']
            );

            $this->assertArrayHasKey(
                $ticket['ticketTypeId'],
                $categories
            );

            $categories[$ticket['ticketTypeId']]++;
        }

        if ($issued) {
            $this->assertSame([
                'general' => 3,
                'vip' => 2,
            ], $categories);
        }
    }

    /**
     * Verificar que se confirmo una sola compra.
     */
    private function assertOnePurchase(
        string $paidOrderId
    ): void {
        $this->assertContains(
            $paidOrderId,
            $this->orderIds
        );

        foreach ($this->orderIds as $index => $orderId) {
            $order = $this->order($orderId);

            if ($orderId === $paidOrderId) {
                $this->assertSame(
                    'paid',
                    $order['status']
                );

                $this->assertCount(
                    5,
                    $order['issuedTicketIds']
                );

                $this->assertSame(
                    'confirmed',
                    $this->reservation($index)['status']
                );

                $this->assertTickets($orderId, true);
            } else {
                $this->assertSame(
                    'pending',
                    $order['status']
                );

                $this->assertSame(
                    'active',
                    $this->reservation($index)['status']
                );

                $this->assertTickets($orderId, false);
            }
        }

        // Se vendieron exactamente tres Generales.
        $general = $this->inventory('general');

        $this->assertSame(4, $general['sold']);
        $this->assertSame(3, $general['reserved']);

        // Se vendieron exactamente dos VIP.
        $vip = $this->inventory('vip');

        $this->assertSame(4, $vip['sold']);
        $this->assertSame(2, $vip['reserved']);

        $payment = $this->firestore
            ->collection('paymentReferences')
            ->document(
                hash('sha256', $this->paymentReference)
            )
            ->snapshot();

        $this->assertTrue($payment->exists());

        $this->assertSame(
            $paidOrderId,
            $payment->data()['orderId']
        );

        $this->assertSame(
            $this->paymentReference,
            $payment->data()['paymentReference']
        );
    }

    /**
     * Confirmar simultaneamente el mismo pedido.
     */
    public function test_same_multiple_order_does_not_duplicate_tickets(): void
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
                    ($result['success'] ?? false) === true
            )
        );

        $this->assertNotEmpty(
            $successful,
            'Ninguna confirmacion pudo completarse.'
                . "\nResultados:\n"
                . $this->describe($results)
        );

        foreach ($successful as $result) {
            $this->assertSame(
                $orderId,
                $result['orderId']
            );

            $this->assertSame(
                'paid',
                $result['status']
            );

            $this->assertCount(
                5,
                $result['ticketIds']
            );
        }

        $this->assertOnePurchase($orderId);
    }

    /**
     * Una referencia no puede pagar dos pedidos.
     */
    public function test_different_multiple_orders_cannot_share_payment(): void
    {
        $results = $this->runTogether(
            $this->orderIds[0],
            $this->orderIds[1]
        );

        $successful = array_values(
            array_filter(
                $results,
                fn (array $result): bool =>
                    ($result['success'] ?? false) === true
            )
        );

        $this->assertCount(
            1,
            $successful,
            'La referencia solo debe confirmar un pedido.'
                . "\nResultados:\n"
                . $this->describe($results)
        );

        $this->assertSame(
            'paid',
            $successful[0]['status']
        );

        $this->assertCount(
            5,
            $successful[0]['ticketIds']
        );

        $this->assertOnePurchase(
            $successful[0]['orderId']
        );
    }

    /**
     * Eliminar los documentos creados por las pruebas.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                $generator = app(TicketIdGenerator::class);

                foreach ($this->orderIds as $orderId) {
                    $ticketIds = $generator
                        ->generateForOrder($orderId, 5);

                    foreach ($ticketIds as $ticketId) {
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
                            hash(
                                'sha256',
                                $this->paymentReference
                            )
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

                    foreach (['general', 'vip'] as $typeId) {
                        $this->eventDocument
                            ->collection('ticketTypes')
                            ->document($typeId)
                            ->delete();
                    }

                    $this->eventDocument->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
