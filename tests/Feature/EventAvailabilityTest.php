<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Query;
use Google\Cloud\Firestore\QuerySnapshot;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EventAvailabilityTest extends TestCase
{
    /**
     * Consultar la disponibilidad de boletos.
     */
    public function test_public_can_view_ticket_availability(): void
    {
        $this->mockAvailability('published', [
            $this->ticketSnapshot('general', 'active', 150, 20),
            $this->ticketSnapshot('vip', 'active', 30, 5),
        ]);

        $this->getJson('/api/events/evento-prueba/availability')
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Disponibilidad consultada correctamente.'
            )
            ->assertJsonPath('eventId', 'evento-prueba')
            ->assertJsonCount(2, 'ticketTypes')
            ->assertJsonPath('ticketTypes.0.ticketTypeId', 'general')
            ->assertJsonPath('ticketTypes.0.capacity', 150)
            ->assertJsonPath('ticketTypes.0.sold', 20)
            ->assertJsonPath('ticketTypes.0.available', 130)
            ->assertJsonPath('ticketTypes.0.isAvailable', true)
            ->assertJsonPath('ticketTypes.0.status', 'available')
            ->assertJsonPath('ticketTypes.1.ticketTypeId', 'vip')
            ->assertJsonPath('ticketTypes.1.available', 25)
            ->assertJsonMissingPath('ticketTypes.0.sellerId');
    }

    /**
     * Identificar boletos agotados.
     */
    public function test_sold_out_ticket_has_zero_availability(): void
    {
        $this->mockAvailability('published', [
            $this->ticketSnapshot('vip', 'active', 30, 30),
        ]);

        $this->getJson('/api/events/evento-prueba/availability')
            ->assertStatus(200)
            ->assertJsonPath('ticketTypes.0.capacity', 30)
            ->assertJsonPath('ticketTypes.0.sold', 30)
            ->assertJsonPath('ticketTypes.0.available', 0)
            ->assertJsonPath('ticketTypes.0.isAvailable', false)
            ->assertJsonPath('ticketTypes.0.status', 'sold_out');
    }

    /**
     * Devolver una lista vacia sin tipos activos.
     */
    public function test_returns_empty_availability(): void
    {
        $this->mockAvailability('published', []);

        $this->getJson('/api/events/evento-prueba/availability')
            ->assertStatus(200)
            ->assertExactJson([
                'message' => 'Disponibilidad consultada correctamente.',
                'eventId' => 'evento-prueba',
                'ticketTypes' => [],
            ]);
    }

    /**
     * Excluir tipos de boletos inactivos.
     */
    public function test_excludes_inactive_ticket_types(): void
    {
        $this->mockAvailability('published', [
            $this->ticketSnapshot('general', 'active', 100, 10),
            $this->ticketSnapshot('vip', 'inactive', 30, 5),
        ]);

        $this->getJson('/api/events/evento-prueba/availability')
            ->assertStatus(200)
            ->assertJsonCount(1, 'ticketTypes')
            ->assertJsonPath('ticketTypes.0.ticketTypeId', 'general')
            ->assertJsonPath('ticketTypes.0.available', 90);
    }

    /**
     * Impedir consultar disponibilidad de eventos en borrador.
     */
    public function test_draft_event_returns_404(): void
    {
        $this->mockEventWithoutAvailability('draft');

        $this->getJson('/api/events/evento-prueba/availability')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Responder 404 cuando el evento no existe.
     */
    public function test_nonexistent_event_returns_404(): void
    {
        $this->mockEventWithoutAvailability(null);

        $this->getJson('/api/events/evento-prueba/availability')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Rechazar identificadores invalidos.
     */
    public function test_rejects_invalid_event_id(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldNotReceive('collection');

        $this->app->instance(FirestoreClient::class, $firestore);

        $this->getJson('/api/events/id.invalido/availability')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Detectar cantidades incorrectas de boletos.
     */
    public function test_rejects_invalid_ticket_quantities(): void
    {
        $this->mockAvailability('published', [
            $this->ticketSnapshot('general', 'active', -10, 5),
        ]);

        $this->getJson('/api/events/evento-prueba/availability')
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible consultar la disponibilidad.'
            );
    }

    /**
     * Manejar fallos de Firestore.
     */
    public function test_handles_firestore_failure(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $events = Mockery::mock(CollectionReference::class);
        $eventDocument = Mockery::mock(DocumentReference::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        $events->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($eventDocument);

        $eventDocument->shouldReceive('snapshot')
            ->once()
            ->andThrow(
                new RuntimeException('Error simulado de Firestore.')
            );

        $this->app->instance(FirestoreClient::class, $firestore);

        $this->getJson('/api/events/evento-prueba/availability')
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible consultar la disponibilidad.'
            );
    }

    /**
     * Simular la consulta de disponibilidad.
     */
    private function mockAvailability(
        string $eventStatus,
        array $documents
    ): void {
        $firestore = Mockery::mock(FirestoreClient::class);
        $events = Mockery::mock(CollectionReference::class);
        $eventDocument = Mockery::mock(DocumentReference::class);
        $eventSnapshot = Mockery::mock(DocumentSnapshot::class);
        $ticketCollection = Mockery::mock(CollectionReference::class);
        $query = Mockery::mock(Query::class);
        $results = Mockery::mock(QuerySnapshot::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        $events->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($eventDocument);

        $eventDocument->shouldReceive('snapshot')
            ->once()
            ->andReturn($eventSnapshot);

        $eventSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $eventSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'name' => 'Concierto de prueba',
                'status' => $eventStatus,
            ]);

        $eventDocument->shouldReceive('collection')
            ->once()
            ->with('ticketTypes')
            ->andReturn($ticketCollection);

        $ticketCollection->shouldReceive('where')
            ->once()
            ->with('status', '=', 'active')
            ->andReturn($query);

        $query->shouldReceive('limit')
            ->once()
            ->with(100)
            ->andReturnSelf();

        $query->shouldReceive('documents')
            ->once()
            ->andReturn($results);

        $results->shouldReceive('getIterator')
            ->andReturn(new \ArrayIterator($documents));

        $this->app->instance(FirestoreClient::class, $firestore);
    }

    /**
     * Simular eventos no publicados o inexistentes.
     */
    private function mockEventWithoutAvailability(
        ?string $status
    ): void {
        $firestore = Mockery::mock(FirestoreClient::class);
        $events = Mockery::mock(CollectionReference::class);
        $eventDocument = Mockery::mock(DocumentReference::class);
        $eventSnapshot = Mockery::mock(DocumentSnapshot::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        $events->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($eventDocument);

        $eventDocument->shouldReceive('snapshot')
            ->once()
            ->andReturn($eventSnapshot);

        $eventSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn($status !== null);

        if ($status !== null) {
            $eventSnapshot->shouldReceive('data')
                ->once()
                ->andReturn([
                    'status' => $status,
                ]);
        }

        $eventDocument->shouldNotReceive('collection');

        $this->app->instance(FirestoreClient::class, $firestore);
    }

    /**
     * Crear un tipo de boleto simulado.
     */
    private function ticketSnapshot(
        string $id,
        string $status,
        int $capacity,
        int $sold
    ): DocumentSnapshot {
        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->andReturn(true);

        $snapshot->shouldReceive('data')
            ->andReturn([
                'sellerId' => 'vendedor-privado',
                'name' => $id === 'vip' ? 'VIP' : 'General',
                'capacity' => $capacity,
                'sold' => $sold,
                'status' => $status,
            ]);

        $snapshot->shouldReceive('id')
            ->andReturn($id);

        return $snapshot;
    }
}
