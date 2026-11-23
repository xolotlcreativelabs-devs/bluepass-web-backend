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

class EventTicketTypeTest extends TestCase
{
    /**
     * Consultar tipos de boletos activos de un evento publicado.
     */
    public function test_public_can_list_active_ticket_types(): void
    {
        $this->mockTicketTypes('published', [
            $this->ticketSnapshot('general', 'active'),
            $this->ticketSnapshot('vip', 'active'),
        ]);

        $this->getJson('/api/events/evento-prueba/ticket-types')
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Tipos de boletos consultados correctamente.'
            )
            ->assertJsonPath('eventId', 'evento-prueba')
            ->assertJsonCount(2, 'ticketTypes')
            ->assertJsonPath('ticketTypes.0.id', 'general')
            ->assertJsonPath('ticketTypes.0.name', 'General')
            ->assertJsonPath('ticketTypes.0.price', 350)
            ->assertJsonPath('ticketTypes.0.currency', 'MXN')
            ->assertJsonPath('ticketTypes.0.capacity', 150)
            ->assertJsonPath('ticketTypes.0.sold', 20)
            ->assertJsonPath('ticketTypes.1.id', 'vip')
            ->assertJsonPath('ticketTypes.1.name', 'VIP')
            ->assertJsonMissingPath('ticketTypes.0.sellerId');
    }

    /**
     * Devolver una lista vacia cuando no existan tipos activos.
     */
    public function test_returns_empty_ticket_types(): void
    {
        $this->mockTicketTypes('published', []);

        $this->getJson('/api/events/evento-prueba/ticket-types')
            ->assertStatus(200)
            ->assertExactJson([
                'message' => 'Tipos de boletos consultados correctamente.',
                'eventId' => 'evento-prueba',
                'ticketTypes' => [],
            ]);
    }

    /**
     * Ocultar tipos de boletos que no esten activos.
     */
    public function test_excludes_inactive_ticket_types(): void
    {
        $this->mockTicketTypes('published', [
            $this->ticketSnapshot('general', 'active'),
            $this->ticketSnapshot('vip', 'inactive'),
        ]);

        $this->getJson('/api/events/evento-prueba/ticket-types')
            ->assertStatus(200)
            ->assertJsonCount(1, 'ticketTypes')
            ->assertJsonPath('ticketTypes.0.id', 'general');
    }

    /**
     * No permitir consultar boletos de eventos en borrador.
     */
    public function test_draft_event_returns_404(): void
    {
        $this->mockEventWithoutTicketQuery('draft');

        $this->getJson('/api/events/evento-prueba/ticket-types')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * No permitir consultar boletos de eventos despublicados.
     */
    public function test_unpublished_event_returns_404(): void
    {
        $this->mockEventWithoutTicketQuery('unpublished');

        $this->getJson('/api/events/evento-prueba/ticket-types')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Responder 404 cuando el evento no exista.
     */
    public function test_nonexistent_event_returns_404(): void
    {
        $this->mockEventWithoutTicketQuery(null);

        $this->getJson('/api/events/evento-prueba/ticket-types')
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

        $this->getJson('/api/events/id.invalido/ticket-types')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Manejar errores de Firestore.
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

        $this->getJson('/api/events/evento-prueba/ticket-types')
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible consultar los tipos de boletos.'
            );
    }

    /**
     * Simular la consulta de tipos de boletos.
     */
    private function mockTicketTypes(
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
    private function mockEventWithoutTicketQuery(
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
        string $status
    ): DocumentSnapshot {
        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->andReturn(true);

        $snapshot->shouldReceive('data')
            ->andReturn([
                'sellerId' => 'vendedor-privado',
                'name' => $id === 'vip' ? 'VIP' : 'General',
                'description' => 'Acceso al evento.',
                'price' => $id === 'vip' ? 1200 : 350,
                'capacity' => $id === 'vip' ? 30 : 150,
                'sold' => $id === 'vip' ? 5 : 20,
                'status' => $status,
            ]);

        $snapshot->shouldReceive('id')
            ->andReturn($id);

        return $snapshot;
    }
}
