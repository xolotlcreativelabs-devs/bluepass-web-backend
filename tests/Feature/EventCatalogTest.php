<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Query;
use Google\Cloud\Firestore\QuerySnapshot;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EventCatalogTest extends TestCase
{
    /**
     * Consultar eventos publicados sin autenticacion.
     */
    public function test_public_can_list_published_events(): void
    {
        $this->mockEvents([
            $this->eventSnapshot('published'),
        ]);

        $this->getJson('/api/events')
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Eventos consultados correctamente.'
            )
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', 'evento-prueba')
            ->assertJsonPath('events.0.name', 'Concierto de prueba')
            ->assertJsonPath('events.0.status', 'published')
            ->assertJsonPath(
                'events.0.timezone',
                'America/Mexico_City'
            )
            ->assertJsonMissingPath('events.0.sellerId');
    }

    /**
     * Devolver una lista vacia cuando no existan eventos publicados.
     */
    public function test_public_can_list_empty_events(): void
    {
        $this->mockEvents([]);

        $this->getJson('/api/events')
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Eventos consultados correctamente.'
            )
            ->assertExactJson([
                'message' => 'Eventos consultados correctamente.',
                'events' => [],
            ]);
    }

    /**
     * Ocultar eventos que no esten publicados.
     */
    public function test_catalog_excludes_unpublished_events(): void
    {
        $this->mockEvents([
            $this->eventSnapshot('draft'),
            $this->eventSnapshot('unpublished'),
            $this->eventSnapshot('published'),
        ]);

        $this->getJson('/api/events')
            ->assertStatus(200)
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.status', 'published');
    }

    /**
     * Responder correctamente si Firestore falla al listar eventos.
     */
    public function test_catalog_handles_firestore_failure(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $events = Mockery::mock(CollectionReference::class);
        $query = Mockery::mock(Query::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        $events->shouldReceive('where')
            ->once()
            ->with('status', '=', 'published')
            ->andReturn($query);

        $query->shouldReceive('limit')
            ->once()
            ->with(20)
            ->andReturnSelf();

        $query->shouldReceive('documents')
            ->once()
            ->andThrow(new RuntimeException('Error simulado de Firestore.'));

        $this->app->instance(FirestoreClient::class, $firestore);

        $this->getJson('/api/events')
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible consultar los eventos.'
            );
    }

    /**
     * Consultar el detalle de un evento publicado.
     */
    public function test_public_can_view_published_event(): void
    {
        $this->mockEventDetail(
            $this->eventSnapshot('published')
        );

        $this->getJson('/api/events/evento-prueba')
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Evento consultado correctamente.'
            )
            ->assertJsonPath('event.id', 'evento-prueba')
            ->assertJsonPath('event.name', 'Concierto de prueba')
            ->assertJsonPath('event.status', 'published')
            ->assertJsonPath(
                'event.timezone',
                'America/Mexico_City'
            )
            ->assertJsonMissingPath('event.sellerId');
    }

    /**
     * Responder 404 cuando el evento no existe.
     */
    public function test_event_detail_returns_404_when_not_found(): void
    {
        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn(false);

        $this->mockEventDetail($snapshot);

        $this->getJson('/api/events/evento-inexistente')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Impedir consultar eventos en borrador.
     */
    public function test_event_detail_excludes_draft_events(): void
    {
        $this->mockEventDetail(
            $this->eventSnapshot('draft')
        );

        $this->getJson('/api/events/evento-prueba')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Impedir consultar eventos despublicados.
     */
    public function test_event_detail_excludes_unpublished_events(): void
    {
        $this->mockEventDetail(
            $this->eventSnapshot('unpublished')
        );

        $this->getJson('/api/events/evento-prueba')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Rechazar identificadores de eventos invalidos.
     */
    public function test_event_detail_rejects_invalid_id(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldNotReceive('collection');

        $this->app->instance(FirestoreClient::class, $firestore);

        $this->getJson('/api/events/id.invalido')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Responder correctamente si Firestore falla al consultar un evento.
     */
    public function test_event_detail_handles_firestore_failure(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $events = Mockery::mock(CollectionReference::class);
        $document = Mockery::mock(DocumentReference::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        $events->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($document);

        $document->shouldReceive('snapshot')
            ->once()
            ->andThrow(new RuntimeException('Error simulado de Firestore.'));

        $this->app->instance(FirestoreClient::class, $firestore);

        $this->getJson('/api/events/evento-prueba')
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible consultar el evento.'
            );
    }

    /**
     * Simular la consulta de eventos publicados.
     */
    private function mockEvents(array $documents): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $events = Mockery::mock(CollectionReference::class);
        $query = Mockery::mock(Query::class);
        $results = Mockery::mock(QuerySnapshot::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        $events->shouldReceive('where')
            ->once()
            ->with('status', '=', 'published')
            ->andReturn($query);

        $query->shouldReceive('limit')
            ->once()
            ->with(20)
            ->andReturnSelf();

        $query->shouldReceive('documents')
            ->once()
            ->andReturn($results);

        $results->shouldReceive('getIterator')
            ->andReturn(new \ArrayIterator($documents));

        $this->app->instance(FirestoreClient::class, $firestore);
    }

    /**
     * Simular la consulta individual de un evento.
     */
    private function mockEventDetail(DocumentSnapshot $snapshot): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $events = Mockery::mock(CollectionReference::class);
        $document = Mockery::mock(DocumentReference::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        $events->shouldReceive('document')
            ->once()
            ->withAnyArgs()
            ->andReturn($document);

        $document->shouldReceive('snapshot')
            ->once()
            ->andReturn($snapshot);

        $this->app->instance(FirestoreClient::class, $firestore);
    }

    /**
     * Crear un documento simulado de Firestore.
     */
    private function eventSnapshot(string $status): DocumentSnapshot
    {
        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->andReturn(true);

        $snapshot->shouldReceive('data')
            ->andReturn([
                'sellerId' => 'vendedor-privado',
                'name' => 'Concierto de prueba',
                'description' => 'Evento musical de prueba.',
                'category' => 'Musica',
                'venue' => 'Auditorio de prueba',
                'timezone' => 'America/Mexico_City',
                'date' => new Timestamp(
                    new DateTimeImmutable('2026-12-10T18:00:00-06:00')
                ),
                'endAt' => new Timestamp(
                    new DateTimeImmutable('2026-12-10T22:00:00-06:00')
                ),
                'imageUrl' => null,
                'status' => $status,
            ]);

        if ($status === 'published') {
            $snapshot->shouldReceive('id')
                ->andReturn('evento-prueba');
        }

        return $snapshot;
    }
}
