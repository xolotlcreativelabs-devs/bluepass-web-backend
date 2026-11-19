<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\DocumentSnapshot;
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
     * Permitir que un vendedor registre una noticia.
     */
    public function test_seller_can_create_news(): void
    {
        $this->mockUser('seller', true);

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
     * Permitir que un administrador registre noticias.
     */
    public function test_admin_can_create_news(): void
    {
        $this->mockUser('admin', true);

        $this->withToken('token-ficticio')
            ->postJson('/api/news', $this->newsData())
            ->assertStatus(201)
            ->assertJsonPath('news.status', 'draft');
    }

    /**
     * Rechazar noticias sin título ni contenido.
     */
    public function test_news_rejects_missing_fields(): void
    {
        $this->mockUser('seller');

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
        $this->mockUser('seller');

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
            // Verificar que el backend asigne autor y estado.
            $document = Mockery::mock(
                DocumentReference::class
            );

            $document->shouldReceive('set')
                ->once()
                ->with(Mockery::on(function ($data) use ($uid) {
                    return is_array($data)
                        && ($data['authorId'] ?? null) === $uid
                        && ($data['status'] ?? null) === 'draft'
                        && ($data['title'] ?? null)
                            === 'Nueva noticia de BluePass'
                        && isset($data['createdAt'])
                        && isset($data['updatedAt']);
                }));

            $document->shouldReceive('id')
                ->once()
                ->andReturn('noticia-prueba');

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
        } else {
            // No acceder a noticias cuando la solicitud se rechaza.
            $firestore->shouldNotReceive('collection')
                ->with('news');
        }

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }
}
