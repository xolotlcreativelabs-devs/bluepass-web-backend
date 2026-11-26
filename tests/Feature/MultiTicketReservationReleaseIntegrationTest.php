<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketReservationReleaseController;
use App\Services\MultiTicketReservationService;
use App\Services\MultiTicketSelectionValidator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class MultiTicketReservationReleaseIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $eventId;

    private $eventDocument;

    private array $ticketDocuments = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Utilizar exclusivamente Firestore Emulator.
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

        $this->eventId = 'test-multi-release-'
            . bin2hex(random_bytes(8));

        $this->eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $now = new DateTimeImmutable();

        $this->eventDocument->set([
            'name' => 'Prueba de cancelacion multiple',
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
     * Crear una reserva de dos VIP y tres Generales.
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
     * Cancelar una reserva con el controlador real.
     */
    private function cancelReservation(
        string $reservationId,
        string $userId = 'comprador-prueba'
    ): JsonResponse {
        $request = Request::create(
            '/api/events/' . $this->eventId
                . '/reservations/' . $reservationId,
            'DELETE'
        );

        $request->attributes->set(
            'firebase_uid',
            $userId
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        return (new TicketReservationReleaseController())->destroy(
            $request,
            $this->eventId,
            $reservationId,
            $this->firestore
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
     * Cancelar ambas categorias de una sola reserva.
     */
    public function test_cancels_multiple_reservation_and_releases_all_inventory(): void
    {
        $reservationId = $this->createReservation();

        $this->assertSame(
            2,
            $this->inventory('vip')['reserved']
        );

        $this->assertSame(
            3,
            $this->inventory('general')['reserved']
        );

        $response = $this->cancelReservation(
            $reservationId
        );

        $this->assertSame(
            200,
            $response->getStatusCode(),
            $response->getContent()
        );

        $data = $response->getData(true);

        $this->assertTrue($data['released']);
        $this->assertSame('cancelled', $data['status']);

        $this->assertSame(
            0,
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

        $this->assertSame(
            10,
            $data['inventories']['vip']['available']
        );

        $this->assertSame(
            10,
            $data['inventories']['general']['available']
        );

        $reservation = $this->eventDocument
            ->collection('reservations')
            ->document($reservationId)
            ->snapshot()
            ->data();

        $this->assertSame(
            'cancelled',
            $reservation['status']
        );
    }

    /**
     * No devolver inventario dos veces.
     */
    public function test_multiple_cancellation_is_idempotent(): void
    {
        $reservationId = $this->createReservation();

        $first = $this->cancelReservation(
            $reservationId
        );

        $second = $this->cancelReservation(
            $reservationId
        );

        $this->assertSame(
            200,
            $first->getStatusCode()
        );

        $this->assertSame(
            200,
            $second->getStatusCode()
        );

        $this->assertTrue(
            $first->getData(true)['released']
        );

        $this->assertFalse(
            $second->getData(true)['released']
        );

        $this->assertSame(
            0,
            $this->inventory('vip')['reserved']
        );

        $this->assertSame(
            0,
            $this->inventory('general')['reserved']
        );
    }

    /**
     * Impedir cancelar una reserva de otro comprador.
     */
    public function test_other_customer_cannot_cancel_multiple_reservation(): void
    {
        $reservationId = $this->createReservation();

        $response = $this->cancelReservation(
            $reservationId,
            'otro-comprador'
        );

        $this->assertSame(
            404,
            $response->getStatusCode()
        );

        $this->assertSame(
            2,
            $this->inventory('vip')['reserved']
        );

        $this->assertSame(
            3,
            $this->inventory('general')['reserved']
        );

        $reservation = $this->eventDocument
            ->collection('reservations')
            ->document($reservationId)
            ->snapshot()
            ->data();

        $this->assertSame(
            'active',
            $reservation['status']
        );
    }

    /**
     * No liberar parcialmente si un inventario es inconsistente.
     */
    public function test_does_not_partially_release_inconsistent_inventory(): void
    {
        $reservationId = $this->createReservation();

        // Simular un contador VIP inconsistente.
        $this->ticketDocuments['vip']->update([
            [
                'path' => 'reserved',
                'value' => 1,
            ],
        ]);

        $response = $this->cancelReservation(
            $reservationId
        );

        $this->assertSame(
            500,
            $response->getStatusCode()
        );

        // Ninguno de los inventarios debe modificarse.
        $this->assertSame(
            1,
            $this->inventory('vip')['reserved']
        );

        $this->assertSame(
            3,
            $this->inventory('general')['reserved']
        );

        $reservation = $this->eventDocument
            ->collection('reservations')
            ->document($reservationId)
            ->snapshot()
            ->data();

        $this->assertSame(
            'active',
            $reservation['status']
        );
    }

    /**
     * Eliminar exclusivamente los documentos temporales.
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
