<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketReservationController;
use App\Http\Controllers\Api\TicketReservationReleaseController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class TicketReservationIntegrationTest extends TestCase
{
    /**
     * Conectar exclusivamente al emulador local.
     */
    private function emulator(): FirestoreClient
    {
        $host = getenv('FIRESTORE_EMULATOR_HOST');
        $project = getenv('GOOGLE_CLOUD_PROJECT');

        if (
            $host !== '127.0.0.1:8080' ||
            $project !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'La prueba requiere el emulador local demo-bluepass-hu04.'
            );
        }

        return new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);
    }

    /**
     * Crear un evento temporal con inventario de prueba.
     */
    private function createTestEvent(
        FirestoreClient $firestore,
        int $capacity = 10
    ): array {
        $eventId = 'prueba-hu04-' . bin2hex(random_bytes(8));

        $eventDocument = $firestore
            ->collection('events')
            ->document($eventId);

        $ticketDocument = $eventDocument
            ->collection('ticketTypes')
            ->document('general');

        $now = new DateTimeImmutable();

        $eventDocument->set([
            'name' => 'Prueba de inventario BluePass',
            'status' => 'published',
            'date' => new Timestamp(
                $now->modify('+10 days')
            ),
        ]);

        $ticketDocument->set([
            'name' => 'General',
            'status' => 'active',
            'price' => 350,
            'capacity' => $capacity,
            'sold' => 0,
            'reserved' => 0,
            'saleStartAt' => new Timestamp(
                $now->modify('-1 hour')
            ),
            'saleEndAt' => new Timestamp(
                $now->modify('+5 days')
            ),
        ]);

        return [
            'eventId' => $eventId,
            'eventDocument' => $eventDocument,
            'ticketDocument' => $ticketDocument,
        ];
    }

    /**
     * Crear una reserva mediante el controlador real.
     */
    private function createReservation(
        FirestoreClient $firestore,
        string $eventId,
        int $quantity = 3
    ): JsonResponse {
        $request = Request::create(
            '/api/events/' . $eventId . '/reservations',
            'POST',
            [
                'ticketTypeId' => 'general',
                'quantity' => $quantity,
            ]
        );

        $request->attributes->set(
            'firebase_uid',
            'comprador-prueba'
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        $controller = new TicketReservationController();

        return $controller->store(
            $request,
            $eventId,
            $firestore
        );
    }

    /**
     * Cancelar una reserva mediante el controlador real.
     */
    private function cancelReservation(
        FirestoreClient $firestore,
        string $eventId,
        string $reservationId
    ): JsonResponse {
        $request = Request::create(
            '/api/events/' . $eventId .
                '/reservations/' . $reservationId,
            'DELETE'
        );

        $request->attributes->set(
            'firebase_uid',
            'comprador-prueba'
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        $controller = new TicketReservationReleaseController();

        return $controller->destroy(
            $request,
            $eventId,
            $reservationId,
            $firestore
        );
    }

    /**
     * Eliminar exclusivamente los documentos temporales.
     */
    private function deleteTestEvent(
        array $testEvent
    ): void {
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

        $testEvent['ticketDocument']->delete();
        $eventDocument->delete();
    }

    /**
     * Crear una reserva y comprobar el inventario.
     */
    public function test_creates_reservation_and_updates_inventory(): void
    {
        $firestore = $this->emulator();

        $testEvent = $this->createTestEvent(
            $firestore,
            10
        );

        try {
            $response = $this->createReservation(
                $firestore,
                $testEvent['eventId'],
                3
            );

            $this->assertSame(
                201,
                $response->getStatusCode(),
                $response->getContent()
            );

            $result = $response->getData(true);

            $this->assertSame(
                'active',
                $result['reservation']['status']
            );

            $this->assertSame(
                3,
                $result['reservation']['quantity']
            );

            $reservationId = $result['reservation']['id'];

            $reservationDocument = $testEvent['eventDocument']
                ->collection('reservations')
                ->document($reservationId);

            $inventory = $testEvent['ticketDocument']
                ->snapshot()
                ->data();

            $this->assertSame(
                10,
                $inventory['capacity']
            );

            $this->assertSame(
                0,
                $inventory['sold']
            );

            $this->assertSame(
                3,
                $inventory['reserved']
            );

            $available = $inventory['capacity']
                - $inventory['sold']
                - $inventory['reserved'];

            $this->assertSame(7, $available);

            $reservation = $reservationDocument
                ->snapshot()
                ->data();

            $this->assertSame(
                'active',
                $reservation['status']
            );

            $this->assertSame(
                3,
                $reservation['quantity']
            );

            $this->assertSame(
                $testEvent['eventId'],
                $reservation['eventId']
            );

            $this->assertSame(
                'comprador-prueba',
                $reservation['userId']
            );
        } finally {
            $this->deleteTestEvent($testEvent);
        }
    }

    /**
     * Cancelar una reserva y liberar su inventario.
     */
    public function test_cancels_reservation_and_releases_inventory(): void
    {
        $firestore = $this->emulator();

        $testEvent = $this->createTestEvent(
            $firestore,
            10
        );

        try {
            $createResponse = $this->createReservation(
                $firestore,
                $testEvent['eventId'],
                3
            );

            $this->assertSame(
                201,
                $createResponse->getStatusCode(),
                $createResponse->getContent()
            );

            $created = $createResponse->getData(true);

            $reservationId = $created['reservation']['id'];

            $reservationDocument = $testEvent['eventDocument']
                ->collection('reservations')
                ->document($reservationId);

            $inventoryBefore = $testEvent['ticketDocument']
                ->snapshot()
                ->data();

            $this->assertSame(
                3,
                $inventoryBefore['reserved']
            );

            $cancelResponse = $this->cancelReservation(
                $firestore,
                $testEvent['eventId'],
                $reservationId
            );

            $this->assertSame(
                200,
                $cancelResponse->getStatusCode(),
                $cancelResponse->getContent()
            );

            $cancelled = $cancelResponse->getData(true);

            $this->assertTrue(
                $cancelled['released']
            );

            $this->assertSame(
                'cancelled',
                $cancelled['status']
            );

            $inventoryAfter = $testEvent['ticketDocument']
                ->snapshot()
                ->data();

            $this->assertSame(
                10,
                $inventoryAfter['capacity']
            );

            $this->assertSame(
                0,
                $inventoryAfter['sold']
            );

            $this->assertSame(
                0,
                $inventoryAfter['reserved']
            );

            $available = $inventoryAfter['capacity']
                - $inventoryAfter['sold']
                - $inventoryAfter['reserved'];

            $this->assertSame(
                10,
                $available
            );

            $reservation = $reservationDocument
                ->snapshot()
                ->data();

            $this->assertSame(
                'cancelled',
                $reservation['status']
            );

            $secondResponse = $this->cancelReservation(
                $firestore,
                $testEvent['eventId'],
                $reservationId
            );

            $this->assertSame(
                200,
                $secondResponse->getStatusCode(),
                $secondResponse->getContent()
            );

            $secondResult = $secondResponse->getData(true);

            $this->assertFalse(
                $secondResult['released']
            );

            $this->assertSame(
                'cancelled',
                $secondResult['status']
            );

            $finalInventory = $testEvent['ticketDocument']
                ->snapshot()
                ->data();

            $this->assertSame(
                0,
                $finalInventory['reserved']
            );

            $this->assertSame(
                10,
                $finalInventory['capacity']
                - $finalInventory['sold']
                - $finalInventory['reserved']
            );
        } finally {
            $this->deleteTestEvent($testEvent);
        }
    }

    /**
     * Comprobar la liberacion automatica de una reserva vencida.
     */
    public function test_expires_reservation_and_releases_inventory(): void
    {
        $firestore = $this->emulator();

        // Inyectar el cliente del emulador en Laravel.
        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );

        $testEvent = $this->createTestEvent(
            $firestore,
            10
        );

        try {
            // Crear una reserva de tres boletos.
            $createResponse = $this->createReservation(
                $firestore,
                $testEvent['eventId'],
                3
            );

            $this->assertSame(
                201,
                $createResponse->getStatusCode(),
                $createResponse->getContent()
            );

            $created = $createResponse->getData(true);

            $reservationId = $created['reservation']['id'];

            $reservationDocument = $testEvent['eventDocument']
                ->collection('reservations')
                ->document($reservationId);

            // Comprobar el inventario inicial.
            $inventoryBefore = $testEvent['ticketDocument']
                ->snapshot()
                ->data();

            $this->assertSame(
                3,
                $inventoryBefore['reserved']
            );

            // Simular el vencimiento de la reserva.
            $expiredAt = new DateTimeImmutable('-1 hour');

            $reservationDocument->update([
                [
                    'path' => 'expiresAt',
                    'value' => new Timestamp($expiredAt),
                ],
            ]);

            // Confirmar que la reserva sigue activa
            // antes de ejecutar el vencimiento.
            $reservationBefore = $reservationDocument
                ->snapshot()
                ->data();

            $this->assertSame(
                'active',
                $reservationBefore['status']
            );

            // Ejecutar el comando contra el emulador.
            $this->artisan('reservations:expire')
                ->expectsOutput('Reservas liberadas: 1')
                ->expectsOutput('Reservas omitidas: 0')
                ->expectsOutput('Reservas con errores: 0')
                ->assertExitCode(0);

            // Comprobar el nuevo estado.
            $reservationAfter = $reservationDocument
                ->snapshot()
                ->data();

            $this->assertSame(
                'expired',
                $reservationAfter['status']
            );

            $this->assertSame(
                3,
                $reservationAfter['quantity']
            );

            // Comprobar que se libero el inventario.
            $inventoryAfter = $testEvent['ticketDocument']
                ->snapshot()
                ->data();

            $this->assertSame(
                10,
                $inventoryAfter['capacity']
            );

            $this->assertSame(
                0,
                $inventoryAfter['sold']
            );

            $this->assertSame(
                0,
                $inventoryAfter['reserved']
            );

            $available = $inventoryAfter['capacity']
                - $inventoryAfter['sold']
                - $inventoryAfter['reserved'];

            $this->assertSame(
                10,
                $available
            );

            // Ejecutar nuevamente el vencimiento.
            $this->artisan('reservations:expire')
                ->expectsOutput('Reservas liberadas: 0')
                ->expectsOutput('Reservas omitidas: 0')
                ->expectsOutput('Reservas con errores: 0')
                ->assertExitCode(0);

            // Comprobar que no existe doble liberacion.
            $finalInventory = $testEvent['ticketDocument']
                ->snapshot()
                ->data();

            $this->assertSame(
                0,
                $finalInventory['reserved']
            );

            $this->assertSame(
                10,
                $finalInventory['capacity']
                - $finalInventory['sold']
                - $finalInventory['reserved']
            );

            $finalReservation = $reservationDocument
                ->snapshot()
                ->data();

            $this->assertSame(
                'expired',
                $finalReservation['status']
            );
        } finally {
            $this->deleteTestEvent($testEvent);
        }
    }
}
