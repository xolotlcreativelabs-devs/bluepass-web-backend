<?php

namespace Tests\Feature;

use App\Services\MultiTicketReservationService;
use App\Services\MultiTicketSelectionValidator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Tests\TestCase;

class MultiTicketReservationExpirationIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $eventDocument;

    private string $eventId;

    private array $ticketDocuments = [];

    private array $orderIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Trabajar exclusivamente con Firestore Emulator.
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

        $this->app->instance(
            FirestoreClient::class,
            $this->firestore
        );

        $this->firestore
            ->collection('users')
            ->document('comprador-prueba')
            ->set([
                'authSubject' => 'comprador-prueba',
                'role' => 'customer',
                'status' => 'active',
            ]);

        $this->eventId = 'test-multi-expire-'
            . bin2hex(random_bytes(8));

        $this->eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $now = new DateTimeImmutable();

        $this->eventDocument->set([
            'name' => 'Prueba de vencimiento multiple',
            'status' => 'published',
            'date' => new Timestamp(
                $now->modify('+10 days')
            ),
        ]);

        foreach ([
            'vip' => 1500,
            'general' => 500,
        ] as $typeId => $price) {
            $document = $this->eventDocument
                ->collection('ticketTypes')
                ->document($typeId);

            $document->set([
                'name' => $typeId,
                'status' => 'active',
                'price' => $price,
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
     * Crear una reserva real con dos tipos de boleto.
     */
    private function createReservation(): string
    {
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

        return $result['reservation']['id'];
    }

    /**
     * Obtener el documento de una reserva.
     */
    private function reservationDocument(
        string $reservationId
    ): DocumentReference {
        return $this->eventDocument
            ->collection('reservations')
            ->document($reservationId);
    }

    /**
     * Simular que una reserva ya vencio.
     */
    private function expireReservation(
        string $reservationId
    ): void {
        $this->reservationDocument($reservationId)->update([
            [
                'path' => 'expiresAt',
                'value' => new Timestamp(
                    new DateTimeImmutable('-1 hour')
                ),
            ],
        ]);
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
     * Consultar una reserva.
     */
    private function reservation(string $reservationId): array
    {
        return $this->reservationDocument($reservationId)
            ->snapshot()
            ->data();
    }

    /**
     * Asociar un pedido de prueba a la reserva.
     */
    private function attachOrder(
        string $reservationId,
        string $status
    ): string {
        $orderId = 'test-multi-order-'
            . bin2hex(random_bytes(8));

        $this->orderIds[] = $orderId;

        $now = new DateTimeImmutable();

        $this->firestore
            ->collection('orders')
            ->document($orderId)
            ->set([
                'eventId' => $this->eventId,
                'userId' => 'comprador-prueba',
                'reservationId' => $reservationId,
                'quantity' => 5,
                'items' => [
                    [
                        'ticketTypeId' => 'general',
                        'quantity' => 3,
                        'unitPrice' => 500,
                        'currency' => 'MXN',
                    ],
                    [
                        'ticketTypeId' => 'vip',
                        'quantity' => 2,
                        'unitPrice' => 1500,
                        'currency' => 'MXN',
                    ],
                ],
                'status' => $status,
                'createdAt' => new Timestamp($now),
                'updatedAt' => new Timestamp($now),
            ]);

        $this->reservationDocument($reservationId)->update([
            [
                'path' => 'orderId',
                'value' => $orderId,
            ],
        ]);

        return $orderId;
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
     * Liberar VIP y General cuando vence una reserva.
     */
    public function test_expires_multiple_reservation_and_releases_all_inventory(): void
    {
        $reservationId = $this->createReservation();

        $this->assertSame(2, $this->inventory('vip')['reserved']);
        $this->assertSame(3, $this->inventory('general')['reserved']);

        $this->expireReservation($reservationId);

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 1')
            ->expectsOutput('Reservas omitidas: 0')
            ->expectsOutput('Reservas con errores: 0')
            ->assertExitCode(0);

        $this->assertSame(
            'expired',
            $this->reservation($reservationId)['status']
        );

        $this->assertSame(0, $this->inventory('vip')['reserved']);
        $this->assertSame(0, $this->inventory('general')['reserved']);

        $this->assertSame(0, $this->inventory('vip')['sold']);
        $this->assertSame(0, $this->inventory('general')['sold']);
    }

    /**
     * Evitar liberar dos veces una reserva vencida.
     */
    public function test_does_not_release_expired_reservation_twice(): void
    {
        $reservationId = $this->createReservation();

        $this->expireReservation($reservationId);

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 1')
            ->expectsOutput('Reservas con errores: 0')
            ->assertExitCode(0);

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 0')
            ->expectsOutput('Reservas con errores: 0')
            ->assertExitCode(0);

        $this->assertSame(0, $this->inventory('vip')['reserved']);
        $this->assertSame(0, $this->inventory('general')['reserved']);

        $this->assertSame(
            'expired',
            $this->reservation($reservationId)['status']
        );
    }

    /**
     * No liberar parcialmente inventarios inconsistentes.
     */
    public function test_rejects_inconsistent_inventory_without_partial_release(): void
    {
        $reservationId = $this->createReservation();

        $this->expireReservation($reservationId);

        // La reserva tiene dos VIP, pero el contador indica uno.
        $this->ticketDocuments['vip']->update([
            [
                'path' => 'reserved',
                'value' => 1,
            ],
        ]);

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 0')
            ->expectsOutput('Reservas con errores: 1')
            ->assertExitCode(1);

        $this->assertSame(1, $this->inventory('vip')['reserved']);
        $this->assertSame(3, $this->inventory('general')['reserved']);

        $this->assertSame(
            'active',
            $this->reservation($reservationId)['status']
        );
    }

    /**
     * No liberar boletos asociados a un pedido pagado.
     */
    public function test_does_not_release_paid_order_inventory(): void
    {
        $reservationId = $this->createReservation();
        $orderId = $this->attachOrder($reservationId, 'paid');

        $this->expireReservation($reservationId);

        // Un pedido pagado no debe liberar su inventario.
        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 0')
            ->expectsOutput('Reservas con errores: 1')
            ->assertExitCode(1);

        $this->assertSame(2, $this->inventory('vip')['reserved']);
        $this->assertSame(3, $this->inventory('general')['reserved']);

        $this->assertSame('paid', $this->order($orderId)['status']);

        $this->assertSame(
            'active',
            $this->reservation($reservationId)['status']
        );
    }

    /**
     * Vencer una reserva multiple y su pedido pendiente.
     */
    public function test_expires_multiple_reservation_and_pending_order(): void
    {
        $reservationId = $this->createReservation();
        $orderId = $this->attachOrder($reservationId, 'pending');

        $this->expireReservation($reservationId);

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 1')
            ->expectsOutput('Reservas con errores: 0')
            ->assertExitCode(0);

        $this->assertSame(
            'expired',
            $this->reservation($reservationId)['status']
        );

        $this->assertSame(
            'expired',
            $this->order($orderId)['status']
        );

        $this->assertSame(0, $this->inventory('vip')['reserved']);
        $this->assertSame(0, $this->inventory('general')['reserved']);
    }

    /**
     * Eliminar exclusivamente los documentos de estas pruebas.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                foreach ($this->orderIds as $orderId) {
                    $this->firestore
                        ->collection('orders')
                        ->document($orderId)
                        ->delete();
                }
            }

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
