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

class NewsUpdateTest extends TestCase
{
    /**
     * Exigir autenticación para editar noticias.
     */
    public function test_news_update_requires_authentication(): void
    {
        $auth = Mockery::mock(Auth::class);
        $auth->shouldNotReceive('verifyIdToken');

        $firestore = Mockery::mock(FirestoreClient::class);
        $firestore->shouldNotReceive('collection');
        $firestore->shouldNotReceive('runTransaction');

        $this->app->instance(Auth::class, $auth);
        $this->app->instance(FirestoreClient::class, $firestore);

        $this->patchJson('/api/news/noticia-prueba', [
            'title' => 'Noticia actualizada',
        ])->assertStatus(401);
    }

    /**
     * Impedir que un comprador edite noticias.
     */
    public function test_customer_cannot_update_news(): void
    {
        $this->mockUser('customer');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba', [
                'title' => 'Noticia actualizada',
            ])
            ->assertStatus(403);
    }

    /**
     * Permitir que un vendedor edite su propia noticia.
     */
    public function test_seller_can_update_own_news(): void
    {
        $this->mockUser('seller', 'update-own');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba', [
                'title' => '  Noticia actualizada  ',
            ])
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Noticia actualizada correctamente.'
            )
            ->assertJsonPath('news.id', 'noticia-prueba')
            ->assertJsonPath(
                'news.authorId',
                'firebase-uid-prueba'
            )
            ->assertJsonPath(
                'news.title',
                'Noticia actualizada'
            )
            ->assertJsonPath('news.status', 'draft');
    }

    /**
     * Impedir que un vendedor edite noticias ajenas.
     */
    public function test_seller_cannot_update_another_sellers_news(): void
    {
        $this->mockUser('seller', 'update-other');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba', [
                'title' => 'Noticia actualizada',
            ])
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'No tienes permisos para editar esta noticia.'
            );
    }

    /**
     * Permitir que un administrador edite noticias ajenas.
     */
    public function test_admin_can_update_another_sellers_news(): void
    {
        $this->mockUser('admin', 'update-admin');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba', [
                'title' => '  Noticia actualizada  ',
            ])
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Noticia actualizada correctamente.'
            )
            ->assertJsonPath(
                'news.authorId',
                'otro-vendedor'
            )
            ->assertJsonPath(
                'news.title',
                'Noticia actualizada'
            );
    }

    /**
     * Permitir eliminar el resumen opcional.
     */
    public function test_seller_can_clear_news_summary(): void
    {
        $this->mockUser('seller', 'update-summary');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba', [
                'summary' => null,
            ])
            ->assertStatus(200)
            ->assertJsonPath('news.summary', null)
            ->assertJsonPath('news.status', 'draft');
    }

    /**
     * Rechazar la edición de una noticia inexistente.
     */
    public function test_news_update_rejects_missing_news(): void
    {
        $this->mockUser('seller', 'update-missing');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba', [
                'title' => 'Noticia actualizada',
            ])
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'La noticia no existe.'
            );
    }

    /**
     * Impedir modificar campos protegidos.
     */
    public function test_news_update_rejects_protected_fields(): void
    {
        $this->mockUser('seller');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba', [
                'authorId' => 'otro-vendedor',
                'status' => 'published',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Se enviaron campos no autorizados.'
            );
    }

    /**
     * Rechazar solicitudes sin cambios.
     */
    public function test_news_update_rejects_empty_request(): void
    {
        $this->mockUser('seller');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba', [])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Debes proporcionar datos para actualizar.'
            );
    }

    /**
     * Rechazar un título demasiado corto.
     */
    public function test_news_update_rejects_invalid_title(): void
    {
        $this->mockUser('seller');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba', [
                'title' => 'ABC',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    /**
     * Manejar errores al actualizar en Firestore.
     */
    public function test_news_update_handles_firestore_failure(): void
    {
        $this->mockUser('seller', 'update-error');

        $this->withToken('token-ficticio')
            ->patchJson('/api/news/noticia-prueba', [
                'title' => 'Noticia actualizada',
            ])
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible actualizar la noticia.'
            );
    }

    /**
     * Simular un usuario autenticado de Firebase.
     */
    private function mockUser(
        string $role,
        string $action = 'none'
    ): void {
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

        if ($action === 'update-error') {
            // Simular un error antes de iniciar la transacción.
            $firestore->shouldReceive('collection')
                ->once()
                ->with('news')
                ->andThrow(new \RuntimeException(
                    'Error simulado de Firestore.'
                ));

            $firestore->shouldNotReceive('runTransaction');

            $firestore->shouldNotReceive('collection')
                ->with('audit_logs');

        } elseif (str_starts_with($action, 'update-')) {
            $this->mockNewsUpdate(
                $firestore,
                $uid,
                $role,
                $action
            );

        } else {
            // No consultar noticias ni crear auditorías
            // cuando la solicitud se rechaza.
            $firestore->shouldNotReceive('collection')
                ->with('news');

            $firestore->shouldNotReceive('collection')
                ->with('audit_logs');

            $firestore->shouldNotReceive('runTransaction');
        }

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }

    /**
     * Simular la consulta y modificación de una noticia.
     */
    private function mockNewsUpdate(
        $firestore,
        string $uid,
        string $role,
        string $action
    ): void {
        $missing = $action === 'update-missing';

        $otherSeller = in_array(
            $action,
            ['update-other', 'update-admin'],
            true
        );

        $authorId = $otherSeller
            ? 'otro-vendedor'
            : $uid;

        // Preparar la respuesta de Firestore.
        $snapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn(!$missing);

        if ($missing) {
            $snapshot->shouldNotReceive('data');
        } else {
            $snapshot->shouldReceive('data')
                ->once()
                ->andReturn([
                    'authorId' => $authorId,
                    'title' => 'Noticia original',
                    'content' => 'Contenido original de la noticia.',
                    'summary' => 'Resumen original.',
                    'status' => 'draft',
                ]);
        }

        $document = Mockery::mock(
            DocumentReference::class
        );

        // La lectura se hace mediante la transacción.
        $document->shouldNotReceive('snapshot');
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

        $canUpdate = in_array(
            $action,
            ['update-own', 'update-admin', 'update-summary'],
            true
        );

        if ($canUpdate) {
            // Preparar el documento del historial.
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

            // Verificar los campos modificados.
            $transaction->shouldReceive('update')
                ->once()
                ->with(
                    $document,
                    Mockery::on(
                        function ($updates) use ($action) {
                            if (
                                !is_array($updates) ||
                                count($updates) !== 2
                            ) {
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

                            if (!isset($fields['updatedAt'])) {
                                return false;
                            }

                            if (
                                array_key_exists('authorId', $fields) ||
                                array_key_exists('status', $fields) ||
                                array_key_exists('createdAt', $fields)
                            ) {
                                return false;
                            }

                            if ($action === 'update-summary') {
                                return array_key_exists('summary', $fields)
                                    && $fields['summary'] === null;
                            }

                            return ($fields['title'] ?? null)
                                === 'Noticia actualizada';
                        }
                    )
                );

            $changedField = $action === 'update-summary'
                ? 'summary'
                : 'title';

            // Verificar que se registre la auditoría.
            $transaction->shouldReceive('create')
                ->once()
                ->with(
                    $auditDocument,
                    Mockery::on(
                        function ($data) use (
                            $uid,
                            $role,
                            $changedField
                        ) {
                            return is_array($data)
                                && count($data) === 7
                                && ($data['action'] ?? null) === 'updated'
                                && ($data['resourceType'] ?? null) === 'news'
                                && ($data['resourceId'] ?? null)
                                    === 'noticia-prueba'
                                && ($data['actorId'] ?? null) === $uid
                                && ($data['actorRole'] ?? null) === $role
                                && ($data['changedFields'] ?? null)
                                    === [$changedField]
                                && isset($data['createdAt']);
                        }
                    )
                );

        } else {
            // Las solicitudes rechazadas no generan cambios.
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
