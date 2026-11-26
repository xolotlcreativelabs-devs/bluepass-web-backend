<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketReservationController;
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

class TicketReservationTest extends TestCase
{
    /**
     * Exigir que el comprador tenga una identidad valida.
     */
    public function test_reservation_requires_identity(): void
    {
        $response = $this->callController(
            $this->validData(),
            null
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Impedir que los vendedores reserven como compradores.
     */
    public function test_seller_cannot_reserve_tickets(): void
    {
        $response = $this->callController(
            $this->validData(),
            'seller'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Validar el identificador del evento.
     */
    public function test_rejects_invalid_event_id(): void
    {
        $response = $this->callController(
            $this->validData(),
            'customer',
            'evento.invalido'
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Exigir el tipo de boleto y la cantidad.
     */
    public function test_rejects_missing_fields(): void
    {
        $response = $this->callController([], 'customer');

        $this->assertSame(422, $response->getStatusCode());

        $errors = $response->getData(true)['errors'];

        $this->assertArrayHasKey('ticketTypeId', $errors);
        $this->assertArrayHasKey('quantity', $errors);
    }

    /**
     * Rechazar cantidades incorrectas.
     */
    public function test_rejects_invalid_quantities(): void
    {
        foreach ([0, -1, 11] as $quantity) {
            $data = $this->validData();
            $data['quantity'] = $quantity;

            $response = $this->callController(
                $data,
                'customer'
            );

            $this->assertSame(
                422,
                $response->getStatusCode()
            );

            $this->assertArrayHasKey(
                'quantity',
                $response->getData(true)['errors']
            );
        }
    }

    /**
     * No permitir que el cliente modifique los contadores.
     */
    public function test_rejects_inventory_fields(): void
    {
        $data = $this->validData();
        $data['reserved'] = 50;

        $response = $this->callController(
            $data,
            'customer'
        );

        $this->assertSame(422, $response->getStatusCode());

        $this->assertSame(
            'Se enviaron campos no autorizados.',
            $response->getData(true)['message']
        );
    }

    /**
     * Impedir reservas cuando no existe inventario suficiente.
     */
    public function test_rejects_insufficient_inventory(): void
    {
        $firestore = $this->mockReservation(
            100,
            95,
            3,
            false
        );

        $response = $this->callController(
            $this->validData(),
            'customer',
            'evento-prueba',
            $firestore
        );

        $this->assertSame(409, $response->getStatusCode());

        $result = $response->getData(true);

        $this->assertSame(
            'No hay suficientes boletos disponibles.',
            $result['message']
        );

        $this->assertSame(2, $result['available']);
    }

    /**
     * Reservar inventario mediante una transaccion.
     */
    public function test_customer_can_create_reservation(): void
    {
        $firestore = $this->mockReservation(
            100,
            25,
            10,
            true
        );

        $response = $this->callController(
            $this->validData(),
            'customer',
            'evento-prueba',
            $firestore
        );

        $this->assertSame(201, $response->getStatusCode());

        $result = $response->getData(true);

        $this->assertSame(
            'Reserva temporal creada correctamente.',
            $result['message']
        );

        $this->assertSame(
            'reserva-prueba',
            $result['reservation']['id']
        );

        $this->assertSame(
            'general',
            $result['reservation']['ticketTypeId']
        );

        $this->assertSame(
            3,
            $result['reservation']['quantity']
        );

        $this->assertSame(
            'active',
            $result['reservation']['status']
        );

        $this->assertSame(
            100,
            $result['inventory']['capacity']
        );

        $this->assertSame(
            25,
            $result['inventory']['sold']
        );

        $this->assertSame(
            13,
            $result['inventory']['reserved']
        );

        $this->assertSame(
            62,
            $result['inventory']['available']
        );

        $this->assertArrayHasKey(
            'expiresAt',
            $result['reservation']
        );
    }

    /**
     * Preparar los datos de una reserva valida.
     */
    private function validData(): array
    {
        return [
            'ticketTypeId' => 'general',
            'quantity' => 3,
        ];
    }

    /**
     * Ejecutar el controlador con un usuario simulado.
     */
    private function callController(
        array $data,
        ?string $role,
        string $eventId = 'evento-prueba',
        ?FirestoreClient $firestore = null
    ): JsonResponse {
        $request = Request::create(
            '/api/events/' . $eventId . '/reservations',
            'POST',
            $data
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

        $firestore ??= Mockery::mock(
            FirestoreClient::class
        );

        $controller = new TicketReservationController();

        return $controller->store(
            $request,
            $eventId,
            $firestore
        );
    }

    /**
     * Simular el inventario y la transaccion de Firestore.
     */
    private function mockReservation(
        int $capacity,
        int $sold,
        int $reserved,
        bool $expectWrite
    ): FirestoreClient {
        $firestore = Mockery::mock(
            FirestoreClient::class
        );

        $users = Mockery::mock(CollectionReference::class);
        $userDocument = Mockery::mock(DocumentReference::class);
        $userSnapshot = Mockery::mock(DocumentSnapshot::class);

        $events = Mockery::mock(
            CollectionReference::class
        );

        $eventDocument = Mockery::mock(
            DocumentReference::class
        );

        $ticketTypes = Mockery::mock(
            CollectionReference::class
        );

        $ticketDocument = Mockery::mock(
            DocumentReference::class
        );

        $reservations = Mockery::mock(
            CollectionReference::class
        );

        $reservationDocument = Mockery::mock(
            DocumentReference::class
        );

        $transaction = Mockery::mock(
            Transaction::class
        );

        $eventSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $ticketSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $now = new DateTimeImmutable();

        $firestore->shouldReceive('collection')
            ->once()
            ->with('users')
            ->andReturn($users);

        $users->shouldReceive('document')
            ->once()
            ->with('comprador-prueba')
            ->andReturn($userDocument);

        $userSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $userSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'authSubject' => 'comprador-prueba',
                'role' => 'customer',
                'status' => 'active',
            ]);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($userDocument)
            ->andReturn($userSnapshot);

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
            ->with('ticketTypes')
            ->andReturn($ticketTypes);

        $ticketTypes->shouldReceive('document')
            ->once()
            ->with('general')
            ->andReturn($ticketDocument);

        $eventDocument->shouldReceive('collection')
            ->once()
            ->with('reservations')
            ->andReturn($reservations);

        $reservations->shouldReceive('newDocument')
            ->once()
            ->andReturn($reservationDocument);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($eventDocument)
            ->andReturn($eventSnapshot);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($ticketDocument)
            ->andReturn($ticketSnapshot);

        $eventSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $eventSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'status' => 'published',
                'date' => new Timestamp(
                    $now->modify('+10 days')
                ),
            ]);

        $ticketSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $ticketSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'name' => 'General',
                'status' => 'active',
                'price' => 350,
                'capacity' => $capacity,
                'sold' => $sold,
                'reserved' => $reserved,
                'saleStartAt' => new Timestamp(
                    $now->modify('-1 hour')
                ),
                'saleEndAt' => new Timestamp(
                    $now->modify('+5 days')
                ),
            ]);

        if ($expectWrite) {
            $transaction->shouldReceive('create')
                ->once()
                ->with(
                    $reservationDocument,
                    Mockery::on(function ($data) {
                        return is_array($data)
                            && ($data['eventId'] ?? null)
                                === 'evento-prueba'
                            && ($data['ticketTypeId'] ?? null)
                                === 'general'
                            && ($data['userId'] ?? null)
                                === 'comprador-prueba'
                            && ($data['quantity'] ?? null) === 3
                            && ($data['status'] ?? null) === 'active'
                            && ($data['expiresAt'] ?? null)
                                instanceof Timestamp;
                    })
                );

            $transaction->shouldReceive('update')
                ->once()
                ->with(
                    $ticketDocument,
                    Mockery::on(function ($updates) {
                        if (!is_array($updates)) {
                            return false;
                        }

                        foreach ($updates as $update) {
                            if (
                                ($update['path'] ?? null) === 'reserved'
                                && ($update['value'] ?? null) === 13
                            ) {
                                return true;
                            }
                        }

                        return false;
                    })
                );

            $reservationDocument->shouldReceive('id')
                ->once()
                ->andReturn('reserva-prueba');
        } else {
            $transaction->shouldNotReceive('create');
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
}
