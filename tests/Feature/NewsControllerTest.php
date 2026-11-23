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

class NewsControllerTest extends TestCase
{
    /**
     * Exigir autenticación para registrar noticias.
     */
    public function test_news_requires_authentication(): void
    {
        $auth = Mockery::mock(Auth::class);
        $auth->shouldNotReceive('verifyIdToken');

        $firestore = Mockery::mock(FirestoreClient::class);
        $firestore->shouldNotReceive('collection');
        $firestore->shouldNotReceive('runTransaction');

        $this->app->instance(Auth::class, $auth);
        $this->app->instance(FirestoreClient::class, $firestore);

        $this->postJson('/api/news', $this->newsData())
            ->assertStatus(401);
    }

    /**
     * Impedir que los compradores registren noticias.
     */
    public function test_customer_cannot_create_news(): void
    {
        $this->mockUser('customer');

        $this->withToken('token-ficticio')
            ->postJson('/api/news', $this->newsData())
            ->assertStatus(403);
    }

    /**
     * Impedir que los vendedores registren noticias.
     */
    public function test_seller_cannot_create_news(): void
    {
        $this->mockUser('seller');

        $this->withToken('token-ficticio')
            ->postJson('/api/news', $this->newsData())
            ->assertStatus(403);
    }

    /**
     * Permitir que un administrador registre noticias.
     */
    public function test_admin_can_create_news(): void
    {
        $this->mockUser('admin', true);

        $this->withToken('token-ficticio')
            ->postJson('/api/news', $this->newsData())
            ->assertStatus(201)
            ->assertJsonPath(
                'message',
                'Noticia registrada como borrador.'
            )
            ->assertJsonPath('news.id', 'noticia-prueba')
            ->assertJsonPath(
                'news.authorId',
                'firebase-uid-prueba'
            )
            ->assertJsonPath('news.status', 'draft')
            ->assertJsonPath(
                'news.title',
                'Nueva noticia de BluePass'
            );
    }

    /**
     * Rechazar noticias sin título ni contenido.
     */
    public function test_news_rejects_missing_fields(): void
    {
        $this->mockUser('admin');

        $this->withToken('token-ficticio')
            ->postJson('/api/news', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'title',
                'content',
            ]);
    }

    /**
     * Impedir que el cliente cambie el autor o estado.
     */
    public function test_news_rejects_protected_fields(): void
    {
        $this->mockUser('admin');

        $data = $this->newsData();
        $data['authorId'] = 'otro-usuario';
        $data['status'] = 'published';

        $this->withToken('token-ficticio')
            ->postJson('/api/news', $data)
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Se enviaron campos no autorizados.'
            );
    }

    /**
     * Preparar los datos de una noticia.
     */
    private function newsData(): array
    {
        return [
            'title' => 'Nueva noticia de BluePass',
            'content' => 'Información importante sobre los próximos eventos.',
            'summary' => 'Información sobre próximos eventos.',
        ];
    }

    /**
     * Simular un usuario autenticado.
     */
    private function mockUser(
        string $role,
        bool $canCreate = false
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

        if ($canCreate) {
            $this->mockNewsCreation(
                $firestore,
                $uid,
                $role
            );
        } else {
            // Las solicitudes rechazadas no crean noticias.
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
     * Simular la creación de una noticia y su auditoría.
     */
    private function mockNewsCreation(
        $firestore,
        string $uid,
        string $role
    ): void {
        $document = Mockery::mock(
            DocumentReference::class
        );

        $document->shouldReceive('id')
            ->once()
            ->andReturn('noticia-prueba');

        $document->shouldNotReceive('set');
        $document->shouldNotReceive('update');

        $news = Mockery::mock(
            CollectionReference::class
        );

        $news->shouldReceive('newDocument')
            ->once()
            ->andReturn($document);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('news')
            ->andReturn($news);

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

        $transaction = Mockery::mock(
            Transaction::class
        );

        // Verificar los datos de la noticia.
        $transaction->shouldReceive('create')
            ->once()
            ->with(
                $document,
                Mockery::on(function ($data) use ($uid) {
                    return is_array($data)
                        && count($data) === 7
                        && ($data['authorId'] ?? null) === $uid
                        && ($data['status'] ?? null) === 'draft'
                        && ($data['title'] ?? null)
                            === 'Nueva noticia de BluePass'
                        && ($data['content'] ?? null)
                            === 'Información importante sobre los próximos eventos.'
                        && ($data['summary'] ?? null)
                            === 'Información sobre próximos eventos.'
                        && isset($data['createdAt'])
                        && isset($data['updatedAt']);
                })
            );

        // Verificar que el administrador quede registrado.
        $transaction->shouldReceive('create')
            ->once()
            ->with(
                $auditDocument,
                Mockery::on(function ($data) use ($uid, $role) {
                    return is_array($data)
                        && count($data) === 7
                        && ($data['action'] ?? null) === 'created'
                        && ($data['resourceType'] ?? null) === 'news'
                        && ($data['resourceId'] ?? null) === 'noticia-prueba'
                        && ($data['actorId'] ?? null) === $uid
                        && ($data['actorRole'] ?? null) === $role
                        && ($data['changedFields'] ?? null)
                            === ['title', 'content', 'summary']
                        && isset($data['createdAt']);
                })
            );

        $transaction->shouldNotReceive('update');

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
