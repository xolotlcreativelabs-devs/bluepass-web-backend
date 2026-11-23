<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Query;
use Google\Cloud\Firestore\QuerySnapshot;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EventCatalogSearchTest extends TestCase
{
    /**
     * Buscar eventos publicados por nombre.
     */
    public function test_search_finds_matching_events(): void
    {
        $this->mockCatalog([
            $this->eventSnapshot(
                'evento-a',
                'Festival de Rock',
                'Musica'
            ),
            $this->eventSnapshot(
                'evento-b',
                'Obra de teatro',
                'Teatro'
            ),
        ]);

        $this->getJson('/api/events?search=rock')
            ->assertStatus(200)
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', 'evento-a')
            ->assertJsonPath(
                'events.0.name',
                'Festival de Rock'
            )
            ->assertJsonPath('pagination.page', 1)
            ->assertJsonPath('pagination.perPage', 20)
            ->assertJsonPath(
                'pagination.matchedInWindow',
                1
            );
    }

    /**
     * Filtrar eventos por categoria.
     */
    public function test_filters_events_by_category(): void
    {
        $this->mockCatalog([
            $this->eventSnapshot(
                'evento-a',
                'Festival de Rock',
                'Musica'
            ),
            $this->eventSnapshot(
                'evento-b',
                'Obra de teatro',
                'Teatro'
            ),
            $this->eventSnapshot(
                'evento-c',
                'Concierto de piano',
                'Musica'
            ),
        ]);

        $this->getJson('/api/events?category=musica')
            ->assertStatus(200)
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('events.0.id', 'evento-a')
            ->assertJsonPath('events.1.id', 'evento-c')
            ->assertJsonPath(
                'pagination.matchedInWindow',
                2
            );
    }

    /**
     * Consultar una pagina especifica.
     */
    public function test_paginates_published_events(): void
    {
        $this->mockCatalog([
            $this->eventSnapshot(
                'evento-e',
                'Evento cinco',
                'Musica'
            ),
            $this->eventSnapshot(
                'evento-c',
                'Evento tres',
                'Musica'
            ),
            $this->eventSnapshot(
                'evento-a',
                'Evento uno',
                'Musica'
            ),
            $this->eventSnapshot(
                'evento-d',
                'Evento cuatro',
                'Musica'
            ),
            $this->eventSnapshot(
                'evento-b',
                'Evento dos',
                'Musica'
            ),
        ]);

        $this->getJson('/api/events?page=2&perPage=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('events.0.id', 'evento-c')
            ->assertJsonPath('events.1.id', 'evento-d')
            ->assertJsonPath('pagination.page', 2)
            ->assertJsonPath('pagination.perPage', 2)
            ->assertJsonPath(
                'pagination.matchedInWindow',
                5
            )
            ->assertJsonPath(
                'pagination.hasNextPage',
                true
            );
    }

    /**
     * Excluir eventos no publicados durante la busqueda.
     */
    public function test_search_excludes_unpublished_events(): void
    {
        $this->mockCatalog([
            $this->eventSnapshot(
                'evento-a',
                'Festival de Rock',
                'Musica',
                'published'
            ),
            $this->eventSnapshot(
                'evento-b',
                'Rock privado',
                'Musica',
                'draft'
            ),
        ]);

        $this->getJson('/api/events?search=rock')
            ->assertStatus(200)
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', 'evento-a')
            ->assertJsonMissingPath('events.0.sellerId');
    }

    /**
     * Rechazar numeros de pagina invalidos.
     */
    public function test_rejects_invalid_page(): void
    {
        $this->mockNoFirestoreQuery();

        $this->getJson('/api/events?page=0')
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Los parametros de consulta no son validos.'
            )
            ->assertJsonStructure([
                'errors' => ['page'],
            ]);
    }

    /**
     * Rechazar parametros no permitidos.
     */
    public function test_rejects_unknown_parameters(): void
    {
        $this->mockNoFirestoreQuery();

        $this->getJson('/api/events?status=draft')
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Los parametros de consulta no son validos.'
            );
    }

    /**
     * Rechazar busquedas demasiado cortas.
     */
    public function test_rejects_short_search(): void
    {
        $this->mockNoFirestoreQuery();

        $this->getJson('/api/events?search=a')
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Los parametros de consulta no son validos.'
            )
            ->assertJsonStructure([
                'errors' => ['search'],
            ]);
    }

    /**
     * Manejar fallos de Firestore durante la busqueda.
     */
    public function test_search_handles_firestore_failure(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $collection = Mockery::mock(CollectionReference::class);
        $query = Mockery::mock(Query::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($collection);

        $collection->shouldReceive('where')
            ->once()
            ->with('status', '=', 'published')
            ->andReturn($query);

        $query->shouldReceive('limit')
            ->once()
            ->with(500)
            ->andReturnSelf();

        $query->shouldReceive('documents')
            ->once()
            ->andThrow(
                new RuntimeException('Error simulado de Firestore.')
            );

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );

        $this->getJson('/api/events?search=rock')
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible consultar los eventos.'
            );
    }

    /**
     * Simular la consulta del catalogo.
     */
    private function mockCatalog(array $documents): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $collection = Mockery::mock(CollectionReference::class);
        $query = Mockery::mock(Query::class);
        $results = Mockery::mock(QuerySnapshot::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($collection);

        $collection->shouldReceive('where')
            ->once()
            ->with('status', '=', 'published')
            ->andReturn($query);

        $query->shouldReceive('limit')
            ->once()
            ->with(500)
            ->andReturnSelf();

        $query->shouldReceive('documents')
            ->once()
            ->andReturn($results);

        $results->shouldReceive('getIterator')
            ->andReturn(
                new \ArrayIterator($documents)
            );

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }

    /**
     * Verificar que no se consulte Firestore con datos invalidos.
     */
    private function mockNoFirestoreQuery(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldNotReceive('collection');

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }

    /**
     * Crear eventos simulados.
     */
    private function eventSnapshot(
        string $id,
        string $name,
        string $category,
        string $status = 'published'
    ): DocumentSnapshot {
        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->andReturn(true);

        $snapshot->shouldReceive('id')
            ->andReturn($id);

        $snapshot->shouldReceive('data')
            ->andReturn([
                'sellerId' => 'vendedor-privado',
                'name' => $name,
                'description' => 'Descripcion de prueba.',
                'category' => $category,
                'venue' => 'Auditorio de prueba',
                'timezone' => 'America/Mexico_City',
                'date' => null,
                'endAt' => null,
                'imageUrl' => null,
                'status' => $status,
            ]);

        return $snapshot;
    }
}
