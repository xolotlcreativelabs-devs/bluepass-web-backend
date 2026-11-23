<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class NewsStatusValidationTest extends TestCase
{
    /**
     * Exigir un estado para actualizar la noticia.
     */
    public function test_news_status_is_required(): void
    {
        $this->mockAdmin();

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * Impedir establecer el estado borrador directamente.
     */
    public function test_news_status_rejects_draft_value(): void
    {
        $this->mockAdmin();

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'draft',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * Rechazar estados desconocidos.
     */
    public function test_news_status_rejects_unknown_value(): void
    {
        $this->mockAdmin();

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'archived',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * Impedir modificar otros campos desde esta ruta.
     */
    public function test_news_status_rejects_extra_fields(): void
    {
        $this->mockAdmin();

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'published',
                'authorId' => 'otro-vendedor',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Se enviaron campos no autorizados.'
            );
    }

    /**
     * Rechazar cambios de estado de noticias inexistentes.
     */
    public function test_news_status_rejects_missing_news(): void
    {
        $this->mockAdmin();
        $this->mockNews(null);

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'La noticia no existe.'
            );
    }

    /**
     * Impedir publicar una noticia sin título válido.
     */
    public function test_news_cannot_be_published_without_valid_title(): void
    {
        $this->mockAdmin();

        $this->mockNews([
            'authorId' => 'firebase-uid-prueba',
            'status' => 'draft',
            'title' => 'ABC',
            'content' => 'Contenido válido de una noticia de BluePass.',
        ]);

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'La noticia debe tener título y contenido válidos para publicarse.'
            );
    }

    /**
     * Impedir publicar una noticia sin contenido válido.
     */
    public function test_news_cannot_be_published_without_valid_content(): void
    {
        $this->mockAdmin();

        $this->mockNews([
            'authorId' => 'firebase-uid-prueba',
            'status' => 'draft',
            'title' => 'Noticia de prueba BluePass',
            'content' => 'Corto',
        ]);

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'La noticia debe tener título y contenido válidos para publicarse.'
            );
    }

    /**
     * Manejar errores de Firestore sin mostrar detalles internos.
     */
    public function test_news_status_handles_firestore_failure(): void
    {
        $this->mockAdmin();

        $firestore = $this->app->make(
            FirestoreClient::class
        );

        // El controlador obtiene la referencia antes
        // de iniciar la transacción.
        $document = Mockery::mock(
            DocumentReference::class
        );

        $news = Mockery::mock(
            CollectionReference::class
        );

        $news->shouldReceive('document')
            ->once()
            ->with('noticia-prueba')
            ->andReturn($document);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('news')
            ->andReturn($news);

        // Simular que Firestore falla al ejecutar la transacción.
        $firestore->shouldReceive('runTransaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andThrow(new \RuntimeException(
                'Error simulado de Firestore.'
            ));

        // No debe intentarse registrar una auditoría.
        $firestore->shouldNotReceive('collection')
            ->with('audit_logs');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible actualizar el estado de la noticia.'
            );
    }

    /**
     * Simular un administrador autenticado de Firebase.
     */
    private function mockAdmin(): void
    {
        $uid = 'firebase-uid-prueba';

        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->once()
            ->andReturn(new DataSet([
                'sub' => $uid,
            ], ''));

        $user = UserRecord::fromResponseData([
            'localId' => $uid,
            'email' => 'administrador@ejemplo.com',
            'emailVerified' => true,
            'disabled' => false,
            'createdAt' => '2026-11-12T12:00:00Z',
        ]);

        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-ficticio', true)
            ->andReturn($token);

        $auth->shouldReceive('getUser')
            ->once()
            ->with($uid)
            ->andReturn($user);

        $this->app->instance(Auth::class, $auth);

        // Simular el perfil del administrador.
        $profileSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $profileSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $profileSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'name' => 'Administrador de prueba',
                'email' => 'administrador@ejemplo.com',
                'role' => 'admin',
                'status' => 'active',
                'authSubject' => $uid,
            ]);

        $userDocument = Mockery::mock(
            DocumentReference::class
        );

        $userDocument->shouldReceive('snapshot')
            ->once()
            ->andReturn($profileSnapshot);

        $users = Mockery::mock(
            CollectionReference::class
        );

        $users->shouldReceive('document')
            ->once()
            ->with($uid)
            ->andReturn($userDocument);

        $firestore = Mockery::mock(
            FirestoreClient::class
        );

        $firestore->shouldReceive('collection')
            ->once()
            ->with('users')
            ->andReturn($users);

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }

    /**
     * Simular una noticia y una transacción sin modificaciones.
     */
    private function mockNews(?array $newsData): void
    {
        $firestore = $this->app->make(
            FirestoreClient::class
        );

        $snapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn($newsData !== null);

        if ($newsData === null) {
            $snapshot->shouldNotReceive('data');
        } else {
            $snapshot->shouldReceive('data')
                ->once()
                ->andReturn($newsData);
        }

        $document = Mockery::mock(
            DocumentReference::class
        );

        $document->shouldNotReceive('update');

        $news = Mockery::mock(
            CollectionReference::class
        );

        $news->shouldReceive('document')
            ->once()
            ->with('noticia-prueba')
            ->andReturn($document);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('news')
            ->andReturn($news);

        $transaction = Mockery::mock(
            Transaction::class
        );

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($document)
            ->andReturn($snapshot);

        // Las solicitudes rechazadas no modifican datos.
        $transaction->shouldNotReceive('update');
        $transaction->shouldNotReceive('create');

        $firestore->shouldNotReceive('collection')
            ->with('audit_logs');

        $firestore->shouldReceive('runTransaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(
                function ($callback) use ($transaction) {
                    return $callback($transaction);
                }
            );
    }
}
