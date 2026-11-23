<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Mockery;
use Tests\TestCase;

class NewsPublicQueryTest extends TestCase
{
    /**
     * Permitir consultar una noticia publicada sin iniciar sesión.
     */
    public function test_public_can_view_published_news(): void
    {
        $this->mockNews('published');

        $this->getJson('/api/news/noticia-prueba')
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Noticia consultada correctamente.'
            )
            ->assertJsonPath('news.id', 'noticia-prueba')
            ->assertJsonPath(
                'news.title',
                'Noticia de prueba BluePass'
            )
            ->assertJsonPath('news.status', 'published');
    }

    /**
     * Impedir que los visitantes consulten borradores.
     */
    public function test_public_cannot_view_draft_news(): void
    {
        $this->mockNews('draft');

        $this->getJson('/api/news/noticia-prueba')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'La noticia no existe.'
            );
    }

    /**
     * Impedir consultar noticias despublicadas.
     */
    public function test_public_cannot_view_unpublished_news(): void
    {
        $this->mockNews('unpublished');

        $this->getJson('/api/news/noticia-prueba')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'La noticia no existe.'
            );
    }

    /**
     * Rechazar consultas de noticias inexistentes.
     */
    public function test_public_cannot_view_missing_news(): void
    {
        $this->mockNews(null);

        $this->getJson('/api/news/noticia-prueba')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'La noticia no existe.'
            );
    }

    /**
     * Rechazar una noticia con información inválida.
     */
    public function test_public_cannot_view_invalid_news(): void
    {
        $this->mockNews('invalid');

        $this->getJson('/api/news/noticia-prueba')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'La noticia no existe.'
            );
    }

    /**
     * Simular la consulta de una noticia en Firestore.
     */
    private function mockNews(?string $status): void
    {
        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn($status !== null);

        if ($status === null) {
            $snapshot->shouldNotReceive('data');
        } else {
            $data = $status === 'invalid'
                ? []
                : [
                    'authorId' => 'vendedor-prueba',
                    'title' => 'Noticia de prueba BluePass',
                    'content' => 'Contenido de una noticia publicada.',
                    'summary' => 'Resumen de la noticia.',
                    'status' => $status,
                ];

            $snapshot->shouldReceive('data')
                ->once()
                ->andReturn($data);
        }

        $document = Mockery::mock(
            DocumentReference::class
        );

        $document->shouldReceive('snapshot')
            ->once()
            ->andReturn($snapshot);

        $news = Mockery::mock(
            CollectionReference::class
        );

        $news->shouldReceive('document')
            ->once()
            ->with('noticia-prueba')
            ->andReturn($document);

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
