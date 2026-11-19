<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Query;
use Mockery;
use Tests\TestCase;

class NewsListTest extends TestCase
{
    /**
     * Permitir consultar noticias publicadas.
     */
    public function test_public_can_list_published_news(): void
    {
        $document = $this->mockNewsDocument(
            'noticia-prueba',
            'published'
        );

        $this->mockNewsQuery([$document]);

        $this->getJson('/api/news')
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Noticias consultadas correctamente.'
            )
            ->assertJsonCount(1, 'news')
            ->assertJsonPath('news.0.id', 'noticia-prueba')
            ->assertJsonPath(
                'news.0.title',
                'Noticia de prueba BluePass'
            )
            ->assertJsonPath('news.0.status', 'published');
    }

    /**
     * Devolver una lista vacía cuando no hay noticias.
     */
    public function test_public_can_list_empty_news(): void
    {
        $this->mockNewsQuery([]);

        $this->getJson('/api/news')
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Noticias consultadas correctamente.'
            )
            ->assertExactJson([
                'message' => 'Noticias consultadas correctamente.',
                'news' => [],
            ]);
    }

    /**
     * No mostrar borradores en el listado público.
     */
    public function test_public_list_excludes_draft_news(): void
    {
        $published = $this->mockNewsDocument(
            'noticia-publicada',
            'published'
        );

        $draft = $this->mockNewsDocument(
            'noticia-borrador',
            'draft',
            false
        );

        $this->mockNewsQuery([
            $published,
            $draft,
        ]);

        $this->getJson('/api/news')
            ->assertStatus(200)
            ->assertJsonCount(1, 'news')
            ->assertJsonPath(
                'news.0.id',
                'noticia-publicada'
            );
    }

    /**
     * Manejar errores al consultar Firestore.
     */
    public function test_news_list_handles_firestore_failure(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('news')
            ->andThrow(new \RuntimeException(
                'Error simulado de Firestore'
            ));

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );

        $this->getJson('/api/news')
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible consultar las noticias.'
            );
    }

    /**
     * Simular una noticia devuelta por Firestore.
     */
    private function mockNewsDocument(
        string $id,
        string $status,
        bool $expectId = true
    ): DocumentSnapshot {
        $document = Mockery::mock(
            DocumentSnapshot::class
        );

        $document->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $document->shouldReceive('data')
            ->once()
            ->andReturn([
                'title' => 'Noticia de prueba BluePass',
                'content' => 'Contenido de una noticia de BluePass.',
                'summary' => 'Resumen de la noticia.',
                'status' => $status,
            ]);

        if ($expectId) {
            $document->shouldReceive('id')
                ->once()
                ->andReturn($id);
        } else {
            $document->shouldNotReceive('id');
        }

        return $document;
    }

    /**
     * Simular el listado público con límite de 20 noticias.
     */
    private function mockNewsQuery(array $documents): void
    {
        $query = Mockery::mock(Query::class);

        $query->shouldReceive('limit')
            ->once()
            ->with(20)
            ->andReturnSelf();

        $query->shouldReceive('documents')
            ->once()
            ->andReturn($documents);

        $news = Mockery::mock(
            CollectionReference::class
        );

        $news->shouldReceive('where')
            ->once()
            ->with('status', '=', 'published')
            ->andReturn($query);

        $firestore = Mockery::mock(
            FirestoreClient::class
        );

        $firestore->shouldReceive('collection')
            ->once()
            ->with('news')
            ->andReturn($news);

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }
}
