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

class NewsStatusControllerTest extends TestCase
{
    /**
     * Permitir que el administrador publique un borrador.
     */
    public function test_admin_can_publish_draft_news(): void
    {
        $this->mockUser('admin');
        $this->mockNews('draft', 'published');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Noticia publicada correctamente.'
            )
            ->assertJsonPath('news.id', 'noticia-prueba')
            ->assertJsonPath('news.status', 'published');
    }

    /**
     * Permitir que el administrador despublique noticias.
     */
    public function test_admin_can_unpublish_news(): void
    {
        $this->mockUser('admin');
        $this->mockNews('published', 'unpublished');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'unpublished',
            ])
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Noticia despublicada correctamente.'
            )
            ->assertJsonPath('news.status', 'unpublished');
    }

    /**
     * Permitir que el administrador vuelva a publicar noticias.
     */
    public function test_admin_can_republish_news(): void
    {
        $this->mockUser('admin');
        $this->mockNews('unpublished', 'published');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(200)
            ->assertJsonPath('news.status', 'published');
    }

    /**
     * Impedir repetir el mismo estado.
     */
    public function test_news_rejects_same_status(): void
    {
        $this->mockUser('admin');
        $this->mockNews('published', null);

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'La noticia ya tiene ese estado.'
            );
    }

    /**
     * Impedir despublicar una noticia que es borrador.
     */
    public function test_draft_news_cannot_be_unpublished(): void
    {
        $this->mockUser('admin');
        $this->mockNews('draft', null);

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'unpublished',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'No se permite este cambio de estado.'
            );
    }

    /**
     * Impedir que un vendedor publique noticias.
     */
    public function test_seller_cannot_publish_news(): void
    {
        $this->mockUser('seller');
        $this->expectNoNewsChanges();

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(403);
    }

    /**
     * Impedir que un vendedor despublique noticias.
     */
    public function test_seller_cannot_unpublish_news(): void
    {
        $this->mockUser('seller');
        $this->expectNoNewsChanges();

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'unpublished',
            ])
            ->assertStatus(403);
    }

    /**
     * Permitir que un administrador publique noticias ajenas.
     */
    public function test_admin_can_publish_another_sellers_news(): void
    {
        $this->mockUser('admin');

        $this->mockNews(
            'draft',
            'published',
            'otro-vendedor'
        );

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Noticia publicada correctamente.'
            )
            ->assertJsonPath('news.status', 'published');
    }

    /**
     * Impedir que los compradores cambien estados.
     */
    public function test_customer_cannot_change_news_status(): void
    {
        $this->mockUser('customer');
        $this->expectNoNewsChanges();

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(403);
    }

    /**
     * Exigir autenticación para cambiar el estado.
     */
    public function test_news_status_requires_authentication(): void
    {
        $auth = Mockery::mock(Auth::class);
        $auth->shouldNotReceive('verifyIdToken');

        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldNotReceive('collection');
        $firestore->shouldNotReceive('runTransaction');

        $this->app->instance(Auth::class, $auth);

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );

        $this->patchJson('/api/news/noticia-prueba/status', [
            'status' => 'published',
        ])->assertStatus(401);
    }

    /**
     * Simular un usuario autenticado de Firebase.
     */
    private function mockUser(string $role): void
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
            'email' => 'usuario@ejemplo.com',
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

        // Simular el perfil del usuario.
        $profileSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $profileSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $profileSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'name' => 'Usuario de prueba',
                'email' => 'usuario@ejemplo.com',
                'role' => $role,
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
     * Confirmar que una solicitud rechazada no modifica noticias.
     */
    private function expectNoNewsChanges(): void
    {
        $firestore = $this->app->make(
            FirestoreClient::class
        );

        $firestore->shouldNotReceive('collection')
            ->with('news');

        $firestore->shouldNotReceive('collection')
            ->with('audit_logs');

        $firestore->shouldNotReceive('runTransaction');
    }

    /**
     * Simular una noticia y su cambio de estado en Firestore.
     */
    private function mockNews(
        string $currentStatus,
        ?string $newStatus,
        string $authorId = 'firebase-uid-prueba',
        string $actorRole = 'admin'
    ): void {
        $firestore = $this->app->make(
            FirestoreClient::class
        );

        $snapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $snapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'authorId' => $authorId,
                'status' => $currentStatus,
                'title' => 'Noticia de prueba BluePass',
                'content' => 'Contenido válido de una noticia de BluePass.',
                'summary' => 'Resumen de prueba.',
            ]);

        $document = Mockery::mock(
            DocumentReference::class
        );

        $document->shouldNotReceive('update');

        if ($newStatus !== null) {
            // Consultar el ID para la auditoría y la respuesta.
            $document->shouldReceive('id')
                ->twice()
                ->andReturn('noticia-prueba');
        }

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

        if ($newStatus !== null) {
            // Preparar el documento de auditoría.
            $auditDocument = Mockery::mock(
                DocumentReference::class
            );

            $auditCollection = Mockery::mock(
                CollectionReference::class
            );

            $auditCollection->shouldReceive('newDocument')
                ->once()
                ->andReturn($auditDocument);

            $firestore->shouldReceive('collection')
                ->once()
                ->with('audit_logs')
                ->andReturn($auditCollection);

            // Verificar la actualización del estado.
            $transaction->shouldReceive('update')
                ->once()
                ->with(
                    $document,
                    Mockery::on(
                        function ($updates) use ($newStatus) {
                            if (!is_array($updates)) {
                                return false;
                            }

                            $fields = [];

                            foreach ($updates as $update) {
                                if (
                                    !is_array($update) ||
                                    !isset($update['path']) ||
                                    !array_key_exists('value', $update)
                                ) {
                                    return false;
                                }

                                $fields[$update['path']] = $update['value'];
                            }

                            return count($fields) === 2
                                && ($fields['status'] ?? null) === $newStatus
                                && isset($fields['updatedAt']);
                        }
                    )
                );

            // Verificar la auditoría en la misma transacción.
            $transaction->shouldReceive('create')
                ->once()
                ->with(
                    $auditDocument,
                    Mockery::on(
                        function ($data) use (
                            $newStatus,
                            $actorRole
                        ) {
                            if (!is_array($data)) {
                                return false;
                            }

                            return count($data) === 7
                                && ($data['action'] ?? null)
                                    === $newStatus
                                && ($data['resourceType'] ?? null)
                                    === 'news'
                                && ($data['resourceId'] ?? null)
                                    === 'noticia-prueba'
                                && ($data['actorId'] ?? null)
                                    === 'firebase-uid-prueba'
                                && ($data['actorRole'] ?? null)
                                    === $actorRole
                                && ($data['changedFields'] ?? null)
                                    === ['status']
                                && isset($data['createdAt']);
                        }
                    )
                );

        } else {
            // Los estados rechazados no modifican Firestore.
            $transaction->shouldNotReceive('update');
            $transaction->shouldNotReceive('create');

            $firestore->shouldNotReceive('collection')
                ->with('audit_logs');
        }

        // Ejecutar la transacción simulada.
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
