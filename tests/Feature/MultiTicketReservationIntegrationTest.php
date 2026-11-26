<?php

namespace Tests\Feature;

use App\Services\MultiTicketReservationService;
use App\Services\MultiTicketSelectionValidator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Tests\TestCase;

class MultiTicketReservationIntegrationTest extends TestCase
{
    /**
     * Conectar exclusivamente al emulador local.
     */
    private function emulator(): FirestoreClient
    {
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'Estas pruebas requieren el emulador local de BluePass.'
            );
        }

        return new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);
    }

    /**
     * Preparar un evento con boletos VIP y Generales.
     */
    private function createEvent(
        FirestoreClient $firestore,
        int $vipCapacity = 5
    ): array {
        $eventId = 'multi-prueba-' . bin2hex(random_bytes(8));

        $eventDocument = $firestore
            ->collection('events')
            ->document($eventId);

        $now = new DateTimeImmutable();

        $eventDocument->set([
            'name' => 'Evento de prueba BluePass',
            'status' => 'published',
            'date' => new Timestamp(
                $now->modify('+10 days')
            ),
        ]);

        $types = [
            'vip' => [
                'price' => 1500,
                'capacity' => $vipCapacity,
            ],
            'general' => [
                'price' => 500,
                'capacity' => 10,
            ],
        ];

        $documents = [];

        foreach ($types as $id => $details) {
            $document = $eventDocument
                ->collection('ticketTypes')
                ->document($id);

            $document->set([
                'name' => $id,
                'status' => 'active',
                'price' => $details['price'],
                'capacity' => $details['capacity'],
                'sold' => 0,
                'reserved' => 0,
                'saleStartAt' => new Timestamp(
                    $now->modify('-1 hour')
                ),
                'saleEndAt' => new Timestamp(
                    $now->modify('+5 days')
                ),
            ]);

            $documents[$id] = $document;
        }

        return [
            'eventId' => $eventId,
            'eventDocument' => $eventDocument,
            'ticketDocuments' => $documents,
        ];
    }

    /**
     * Eliminar exclusivamente los datos de esta prueba.
     */
    private function deleteEvent(array $testEvent): void
    {
        $eventDocument = $testEvent['eventDocument'];

        foreach (
            $eventDocument
                ->collection('reservations')
                ->documents() as $reservation
        ) {
            if ($reservation->exists()) {
                $reservation->reference()->delete();
            }
        }

        foreach ($testEvent['ticketDocuments'] as $document) {
            $document->delete();
        }

        $eventDocument->delete();
    }

    /**
     * Crear el servicio con el cliente del emulador.
     */
    private function service(
        FirestoreClient $firestore
    ): MultiTicketReservationService {
        return new MultiTicketReservationService(
            $firestore,
            new MultiTicketSelectionValidator()
        );
    }

    /**
     * Reservar dos VIP y tres Generales.
     */
    public function test_reserves_multiple_ticket_types(): void
    {
        $firestore = $this->emulator();
        $event = $this->createEvent($firestore);

        try {
            $result = $this->service($firestore)->create(
                $event['eventId'],
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

            $this->assertSame(201, $result['code']);

            $this->assertSame(
                5,
                $result['reservation']['quantity']
            );

            $this->assertCount(
                2,
                $result['reservation']['items']
            );

            $this->assertSame(
                'active',
                $result['reservation']['status']
            );

            $reservationId = $result['reservation']['id'];

            $reservation = $event['eventDocument']
                ->collection('reservations')
                ->document($reservationId)
                ->snapshot();

            $this->assertTrue($reservation->exists());

            $data = $reservation->data();

            $this->assertSame(
                'comprador-prueba',
                $data['userId']
            );

            $this->assertSame(5, $data['quantity']);

            $this->assertCount(2, $data['items']);

            $vip = $event['ticketDocuments']['vip']
                ->snapshot()
                ->data();

            $general = $event['ticketDocuments']['general']
                ->snapshot()
                ->data();

            $this->assertSame(2, $vip['reserved']);
            $this->assertSame(3, $general['reserved']);
            $this->assertSame(0, $vip['sold']);
            $this->assertSame(0, $general['sold']);
        } finally {
            $this->deleteEvent($event);
        }
    }

    /**
     * No reservar ninguna categoria si falta inventario.
     */
    public function test_rejects_entire_reservation_when_one_type_is_unavailable(): void
    {
        $firestore = $this->emulator();

        // Solo existe un boleto VIP disponible.
        $event = $this->createEvent($firestore, 1);

        try {
            $result = $this->service($firestore)->create(
                $event['eventId'],
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

            $this->assertSame(409, $result['code']);

            $this->assertSame(
                'vip',
                $result['ticketTypeId']
            );

            $this->assertSame(
                1,
                $result['available']
            );

            $vip = $event['ticketDocuments']['vip']
                ->snapshot()
                ->data();

            $general = $event['ticketDocuments']['general']
                ->snapshot()
                ->data();

            $this->assertSame(0, $vip['reserved']);
            $this->assertSame(0, $general['reserved']);

            $reservations = $event['eventDocument']
                ->collection('reservations')
                ->documents();

            $this->assertSame(
                0,
                $reservations->size()
            );
        } finally {
            $this->deleteEvent($event);
        }
    }

    /**
     * Rechazar toda la reserva si una categoria esta inactiva.
     */
    public function test_rejects_reservation_when_one_type_is_inactive(): void
    {
        $firestore = $this->emulator();
        $event = $this->createEvent($firestore);

        try {
            $event['ticketDocuments']['vip']->update([
                [
                    'path' => 'status',
                    'value' => 'inactive',
                ],
            ]);

            $result = $this->service($firestore)->create(
                $event['eventId'],
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
                409,
                $result['code']
            );

            foreach (
                $event['ticketDocuments'] as $document
            ) {
                $inventory = $document
                    ->snapshot()
                    ->data();

                $this->assertSame(
                    0,
                    $inventory['reserved']
                );
            }

            $reservations = $event['eventDocument']
                ->collection('reservations')
                ->documents();

            $this->assertSame(
                0,
                $reservations->size()
            );
        } finally {
            $this->deleteEvent($event);
        }
    }

    /**
     * Rechazar toda la reserva si un tipo esta fuera de venta.
     */
    public function test_rejects_reservation_when_one_type_is_outside_sale_period(): void
    {
        $firestore = $this->emulator();
        $event = $this->createEvent($firestore);

        try {
            $now = new DateTimeImmutable();

            $event['ticketDocuments']['vip']->update([
                [
                    'path' => 'saleStartAt',
                    'value' => new Timestamp(
                        $now->modify('+1 day')
                    ),
                ],
            ]);

            $result = $this->service($firestore)->create(
                $event['eventId'],
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
                409,
                $result['code']
            );

            foreach (
                $event['ticketDocuments'] as $document
            ) {
                $inventory = $document
                    ->snapshot()
                    ->data();

                $this->assertSame(
                    0,
                    $inventory['reserved']
                );
            }

            $reservations = $event['eventDocument']
                ->collection('reservations')
                ->documents();

            $this->assertSame(
                0,
                $reservations->size()
            );
        } finally {
            $this->deleteEvent($event);
        }
    }
}
