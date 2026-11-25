<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketReservationReleaseController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class TicketReservationReleaseTest extends TestCase
{
    /**
     * Exigir una identidad autenticada.
     */
    public function test_cancellation_requires_identity(): void
    {
        $response = $this->callController(null);

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Impedir cancelaciones desde cuentas de vendedores.
     */
    public function test_seller_cannot_cancel_reservations(): void
    {
        $response = $this->callController('seller');

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Rechazar un identificador de evento incorrecto.
     */
    public function test_rejects_invalid_event_id(): void
    {
        $response = $this->callController(
            'customer',
            'evento.invalido'
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Rechazar un identificador de reserva incorrecto.
     */
    public function test_rejects_invalid_reservation_id(): void
    {
        $response = $this->callController(
            'customer',
            'evento-prueba',
            'reserva.invalida'
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Impedir cancelar reservas inexistentes.
     */
    public function test_missing_reservation_returns_404(): void
    {
        $firestore = $this->mockReservation(
            null,
            'comprador-prueba',
            10,
            false
        );

        $response = $this->callController(
            'customer',
            'evento-prueba',
            'reserva-prueba',
            $firestore
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Impedir cancelar reservas de otros compradores.
     */
    public function test_customer_cannot_cancel_another_users_reservation(): void
    {
        $firestore = $this->mockReservation(
            'active',
            'otro-comprador',
            10,
            false
        );

        $response = $this->callController(
            'customer',
            'evento-prueba',
            'reserva-prueba',
            $firestore
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * No liberar dos veces una reserva cancelada.
     */
    public function test_cancelled_reservation_is_idempotent(): void
    {
        $firestore = $this->mockReservation(
            'cancelled',
            'comprador-prueba',
            10,
            false
        );

        $response = $this->callController(
            'customer',
            'evento-prueba',
            'reserva-prueba',
            $firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $result = $response->getData(true);

        $this->assertFalse($result['released']);
        $this->assertSame('cancelled', $result['status']);
    }

    /**
     * No liberar dos veces una reserva vencida.
     */
    public function test_expired_reservation_is_idempotent(): void
    {
        $firestore = $this->mockReservation(
            'expired',
            'comprador-prueba',
            10,
            false
        );

        $response = $this->callController(
            'customer',
            'evento-prueba',
            'reserva-prueba',
            $firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $result = $response->getData(true);

        $this->assertFalse($result['released']);
        $this->assertSame('expired', $result['status']);
    }

    /**
     * No cancelar reservas con estados diferentes de active.
     */
    public function test_paid_reservation_cannot_be_cancelled(): void
    {
        $firestore = $this->mockReservation(
            'confirmed',
            'comprador-prueba',
            10,
            false
        );

        $response = $this->callController(
            'customer',
            'evento-prueba',
            'reserva-prueba',
            $firestore
        );

        $this->assertSame(409, $response->getStatusCode());
    }

    /**
     * Cancelar una reserva activa y devolver el inventario.
     */
    public function test_customer_can_cancel_active_reservation(): void
    {
        $firestore = $this->mockReservation(
            'active',
            'comprador-prueba',
            10,
            true
        );

        $response = $this->callController(
            'customer',
            'evento-prueba',
            'reserva-prueba',
            $firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $result = $response->getData(true);

        $this->assertTrue($result['released']);
        $this->assertSame('cancelled', $result['status']);
        $this->assertSame(100, $result['inventory']['capacity']);
        $this->assertSame(25, $result['inventory']['sold']);
        $this->assertSame(7, $result['inventory']['reserved']);
        $this->assertSame(68, $result['inventory']['available']);
    }

    /**
     * Identificar como vencida una reserva activa caducada.
     */
    public function test_expired_active_reservation_is_released(): void
    {
        $firestore = $this->mockReservation(
            'active',
            'comprador-prueba',
            10,
            true,
            true
        );

        $response = $this->callController(
            'customer',
            'evento-prueba',
            'reserva-prueba',
            $firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $result = $response->getData(true);

        $this->assertTrue($result['released']);
        $this->assertSame('expired', $result['status']);
        $this->assertSame(7, $result['inventory']['reserved']);
    }

    /**
     * No modificar inventarios con contadores insuficientes.
     */
    public function test_rejects_inconsistent_inventory(): void
    {
        $firestore = $this->mockReservation(
            'active',
            'comprador-prueba',
            2,
            false
        );

        $response = $this->callController(
            'customer',
            'evento-prueba',
            'reserva-prueba',
            $firestore
        );

        $this->assertSame(500, $response->getStatusCode());

        $this->assertSame(
            'No fue posible liberar la reserva.',
            $response->getData(true)['message']
        );
    }

    /**
     * Ejecutar el controlador con una identidad de prueba.
     */
    private function callController(
        ?string $role,
        string $eventId = 'evento-prueba',
        string $reservationId = 'reserva-prueba',
        ?FirestoreClient $firestore = null
    ): JsonResponse {
        $request = Request::create(
            '/api/events/' . $eventId .
                '/reservations/' . $reservationId,
            'DELETE'
        );

        if ($role !== null) {
            $request->attributes->set(
                'firebase_uid',
                'comprador-prueba'
            );

            $request->attributes->set(
                'firebase_role',
                $role
            );
        }

        $firestore ??= Mockery::mock(FirestoreClient::class);

        return (new TicketReservationReleaseController())->destroy(
            $request,
            $eventId,
            $reservationId,
            $firestore
        );
    }

    /**
     * Simular reservas, inventarios y transacciones.
     */
    private function mockReservation(
        ?string $status,
        string $ownerId,
        int $reserved,
        bool $expectRelease,
        bool $pastExpiration = false
    ): FirestoreClient {
        $firestore = Mockery::mock(FirestoreClient::class);
        $events = Mockery::mock(CollectionReference::class);
        $eventDocument = Mockery::mock(DocumentReference::class);
        $reservations = Mockery::mock(CollectionReference::class);
        $reservationDocument = Mockery::mock(DocumentReference::class);
        $reservationSnapshot = Mockery::mock(DocumentSnapshot::class);
        $transaction = Mockery::mock(Transaction::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        $events->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($eventDocument);

        $eventDocument->shouldReceive('collection')
            ->once()
            ->with('reservations')
            ->andReturn($reservations);

        $reservations->shouldReceive('document')
            ->once()
            ->with('reserva-prueba')
            ->andReturn($reservationDocument);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($reservationDocument)
            ->andReturn($reservationSnapshot);

        $reservationSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn($status !== null);

        if ($status !== null) {
            $expiration = (new DateTimeImmutable())->modify(
                $pastExpiration ? '-1 hour' : '+1 hour'
            );

            $reservationSnapshot->shouldReceive('data')
                ->once()
                ->andReturn([
                    'userId' => $ownerId,
                    'eventId' => 'evento-prueba',
                    'ticketTypeId' => 'general',
                    'quantity' => 3,
                    'status' => $status,
                    'expiresAt' => new Timestamp($expiration),
                ]);
        }

        if (
            $status === 'active' &&
            $ownerId === 'comprador-prueba'
        ) {
            $ticketTypes = Mockery::mock(CollectionReference::class);
            $ticketDocument = Mockery::mock(DocumentReference::class);
            $ticketSnapshot = Mockery::mock(DocumentSnapshot::class);

            $eventDocument->shouldReceive('collection')
                ->once()
                ->with('ticketTypes')
                ->andReturn($ticketTypes);

            $ticketTypes->shouldReceive('document')
                ->once()
                ->with('general')
                ->andReturn($ticketDocument);

            $transaction->shouldReceive('snapshot')
                ->once()
                ->with($ticketDocument)
                ->andReturn($ticketSnapshot);

            $ticketSnapshot->shouldReceive('exists')
                ->once()
                ->andReturn(true);

            $ticketSnapshot->shouldReceive('data')
                ->once()
                ->andReturn([
                    'capacity' => 100,
                    'sold' => 25,
                    'reserved' => $reserved,
                ]);

            if ($expectRelease) {
                $transaction->shouldReceive('update')
                    ->once()
                    ->with(
                        $ticketDocument,
                        Mockery::on(function ($updates) use ($reserved) {
                            return $this->hasUpdate(
                                $updates,
                                'reserved',
                                $reserved - 3
                            );
                        })
                    );

                $expectedStatus = $pastExpiration
                    ? 'expired'
                    : 'cancelled';

                $transaction->shouldReceive('update')
                    ->once()
                    ->with(
                        $reservationDocument,
                        Mockery::on(function ($updates) use ($expectedStatus) {
                            return $this->hasUpdate(
                                $updates,
                                'status',
                                $expectedStatus
                            );
                        })
                    );
            } else {
                $transaction->shouldNotReceive('update');
            }
        } else {
            $eventDocument->shouldNotReceive('collection')
                ->with('ticketTypes');

            $transaction->shouldNotReceive('update');
        }

        $firestore->shouldReceive('runTransaction')
            ->once()
            ->andReturnUsing(
                function ($callback) use ($transaction) {
                    return $callback($transaction);
                }
            );

        return $firestore;
    }

    /**
     * Comprobar un campo dentro de una actualizacion.
     */
    private function hasUpdate(
        mixed $updates,
        string $path,
        mixed $value
    ): bool {
        if (!is_array($updates)) {
            return false;
        }

        foreach ($updates as $update) {
            if (
                is_array($update) &&
                ($update['path'] ?? null) === $path &&
                ($update['value'] ?? null) === $value
            ) {
                return true;
            }
        }

        return false;
    }
}
