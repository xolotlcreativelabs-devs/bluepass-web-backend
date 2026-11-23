<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Query;
use Google\Cloud\Firestore\QuerySnapshot;
use Google\Cloud\Firestore\Transaction;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FavoriteControllerTest extends TestCase
{
    /**
     * Exigir autenticacion en los endpoints de favoritos.
     */
    public function test_favorites_require_authentication(): void
    {
        $auth = Mockery::mock(Auth::class);
        $auth->shouldNotReceive('verifyIdToken');

        $firestore = Mockery::mock(FirestoreClient::class);
        $firestore->shouldNotReceive('collection');

        $this->app->instance(Auth::class, $auth);
        $this->app->instance(FirestoreClient::class, $firestore);

        $this->getJson('/api/users/me/favorites')
            ->assertStatus(401);

        $this->postJson(
            '/api/users/me/favorites/evento-prueba'
        )->assertStatus(401);

        $this->deleteJson(
            '/api/users/me/favorites/evento-prueba'
        )->assertStatus(401);
    }

    /**
     * Consultar los favoritos del usuario autenticado.
     */
    public function test_user_can_list_own_favorites(): void
    {
        [$firestore, $userDocument] = $this->mockUser();

        $favorites = Mockery::mock(CollectionReference::class);
        $query = Mockery::mock(Query::class);
        $results = Mockery::mock(QuerySnapshot::class);
        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $userDocument->shouldReceive('collection')
            ->once()
            ->with('favorites')
            ->andReturn($favorites);

        $favorites->shouldReceive('limit')
            ->once()
            ->with(100)
            ->andReturn($query);

        $query->shouldReceive('documents')
            ->once()
            ->andReturn($results);

        $snapshot->shouldReceive('exists')
            ->andReturn(true);

        $snapshot->shouldReceive('id')
            ->andReturn('evento-prueba');

        $snapshot->shouldReceive('data')
            ->andReturn([
                'eventId' => 'evento-prueba',
                'createdAt' => null,
            ]);

        $results->shouldReceive('getIterator')
            ->andReturn(new \ArrayIterator([$snapshot]));

        $this->withToken('token-ficticio')
            ->getJson('/api/users/me/favorites')
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Favoritos consultados correctamente.'
            )
            ->assertJsonCount(1, 'favorites')
            ->assertJsonPath(
                'favorites.0.eventId',
                'evento-prueba'
            )
            ->assertJsonMissingPath('favorites.0.sellerId');
    }

    /**
     * Agregar un evento publicado a favoritos.
     */
    public function test_user_can_add_published_event(): void
    {
        $this->mockStoreFavorite();

        $this->withToken('token-ficticio')
            ->postJson(
                '/api/users/me/favorites/evento-prueba'
            )
            ->assertStatus(201)
            ->assertJsonPath(
                'message',
                'Evento agregado a favoritos.'
            )
            ->assertJsonPath('eventId', 'evento-prueba')
            ->assertJsonPath('created', true);
    }

    /**
     * Agregar nuevamente un favorito no crea duplicados.
     */
    public function test_adding_existing_favorite_is_idempotent(): void
    {
        $this->mockStoreFavorite(alreadyExists: true);

        $this->withToken('token-ficticio')
            ->postJson(
                '/api/users/me/favorites/evento-prueba'
            )
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'El evento ya esta en favoritos.'
            )
            ->assertJsonPath('created', false);
    }

    /**
     * Impedir guardar eventos en borrador.
     */
    public function test_cannot_add_draft_event(): void
    {
        $this->mockStoreFavorite(eventStatus: 'draft');

        $this->withToken('token-ficticio')
            ->postJson(
                '/api/users/me/favorites/evento-prueba'
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Impedir guardar eventos inexistentes.
     */
    public function test_cannot_add_missing_event(): void
    {
        $this->mockStoreFavorite(eventStatus: null);

        $this->withToken('token-ficticio')
            ->postJson(
                '/api/users/me/favorites/evento-prueba'
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Eliminar un favorito existente.
     */
    public function test_user_can_remove_favorite(): void
    {
        $this->mockDeleteFavorite(exists: true);

        $this->withToken('token-ficticio')
            ->deleteJson(
                '/api/users/me/favorites/evento-prueba'
            )
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Favorito eliminado correctamente.'
            )
            ->assertJsonPath('removed', true);
    }

    /**
     * Eliminar un favorito inexistente no produce errores.
     */
    public function test_removing_missing_favorite_is_idempotent(): void
    {
        $this->mockDeleteFavorite(exists: false);

        $this->withToken('token-ficticio')
            ->deleteJson(
                '/api/users/me/favorites/evento-prueba'
            )
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'El evento no estaba en favoritos.'
            )
            ->assertJsonPath('removed', false);
    }

    /**
     * Rechazar identificadores de eventos invalidos.
     */
    public function test_favorites_reject_invalid_event_id(): void
    {
        [$firestore, $userDocument] = $this->mockUser(
            userLookups: 1
        );

        $userDocument->shouldNotReceive('collection');

        $firestore->shouldNotReceive('runTransaction');

        $this->withToken('token-ficticio')
            ->postJson(
                '/api/users/me/favorites/id.invalido'
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Manejar errores de Firestore al guardar favoritos.
     */
    public function test_favorite_handles_firestore_failure(): void
    {
        [$firestore, $userDocument] = $this->mockUser(
            userLookups: 1
        );

        $userDocument->shouldNotReceive('collection');

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andThrow(
                new RuntimeException('Error simulado de Firestore.')
            );

        $this->withToken('token-ficticio')
            ->postJson(
                '/api/users/me/favorites/evento-prueba'
            )
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible agregar el favorito.'
            );
    }

    /**
     * Impedir gestionar favoritos de cuentas suspendidas.
     */
    public function test_suspended_user_cannot_access_favorites(): void
    {
        $this->mockUser(
            status: 'suspended',
            userLookups: 1
        );

        $this->withToken('token-ficticio')
            ->getJson('/api/users/me/favorites')
            ->assertStatus(403);
    }

    /**
     * Simular Firebase Authentication y el perfil de Firestore.
     */
    private function mockUser(
        string $status = 'active',
        int $userLookups = 2
    ): array {
        $uid = 'firebase-uid-prueba';

        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->once()
            ->andReturn(new DataSet([
                'sub' => $uid,
            ], ''));

        $user = UserRecord::fromResponseData([
            'localId' => $uid,
            'email' => 'cliente@ejemplo.com',
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

        $profileSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $profileSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $profileSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'name' => 'Cliente Prueba',
                'email' => 'cliente@ejemplo.com',
                'role' => 'customer',
                'status' => $status,
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
            ->times($userLookups)
            ->with($uid)
            ->andReturn($userDocument);

        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldReceive('collection')
            ->times($userLookups)
            ->with('users')
            ->andReturn($users);

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );

        return [$firestore, $userDocument];
    }

    /**
     * Simular la transaccion para agregar un favorito.
     */
    private function mockStoreFavorite(
        ?string $eventStatus = 'published',
        bool $alreadyExists = false
    ): void {
        [$firestore, $userDocument] = $this->mockUser();

        $events = Mockery::mock(CollectionReference::class);
        $eventDocument = Mockery::mock(DocumentReference::class);
        $eventSnapshot = Mockery::mock(DocumentSnapshot::class);

        $favorites = Mockery::mock(CollectionReference::class);
        $favoriteDocument = Mockery::mock(DocumentReference::class);
        $favoriteSnapshot = Mockery::mock(DocumentSnapshot::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        $events->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($eventDocument);

        $userDocument->shouldReceive('collection')
            ->once()
            ->with('favorites')
            ->andReturn($favorites);

        $favorites->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($favoriteDocument);

        $eventSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn($eventStatus !== null);

        if ($eventStatus !== null) {
            $eventSnapshot->shouldReceive('data')
                ->once()
                ->andReturn([
                    'status' => $eventStatus,
                ]);
        }

        $transaction = Mockery::mock(Transaction::class);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($eventDocument)
            ->andReturn($eventSnapshot);

        if ($eventStatus === 'published') {
            $favoriteSnapshot->shouldReceive('exists')
                ->once()
                ->andReturn($alreadyExists);

            $transaction->shouldReceive('snapshot')
                ->once()
                ->with($favoriteDocument)
                ->andReturn($favoriteSnapshot);

            if ($alreadyExists) {
                $transaction->shouldNotReceive('create');
            } else {
                $transaction->shouldReceive('create')
                    ->once()
                    ->with(
                        $favoriteDocument,
                        Mockery::on(function ($data): bool {
                            return is_array($data)
                                && ($data['eventId'] ?? null)
                                    === 'evento-prueba'
                                && isset($data['createdAt']);
                        })
                    );
            }
        } else {
            $transaction->shouldNotReceive('create');
        }

        $firestore->shouldReceive('runTransaction')
            ->once()
            ->andReturnUsing(
                function ($callback) use ($transaction) {
                    return $callback($transaction);
                }
            );
    }

    /**
     * Simular la transaccion para eliminar un favorito.
     */
    private function mockDeleteFavorite(bool $exists): void
    {
        [$firestore, $userDocument] = $this->mockUser();

        $favorites = Mockery::mock(CollectionReference::class);
        $favoriteDocument = Mockery::mock(DocumentReference::class);
        $favoriteSnapshot = Mockery::mock(DocumentSnapshot::class);

        $userDocument->shouldReceive('collection')
            ->once()
            ->with('favorites')
            ->andReturn($favorites);

        $favorites->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($favoriteDocument);

        $favoriteSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn($exists);

        $transaction = Mockery::mock(Transaction::class);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($favoriteDocument)
            ->andReturn($favoriteSnapshot);

        if ($exists) {
            $transaction->shouldReceive('delete')
                ->once()
                ->with($favoriteDocument);
        } else {
            $transaction->shouldNotReceive('delete');
        }

        $firestore->shouldReceive('runTransaction')
            ->once()
            ->andReturnUsing(
                function ($callback) use ($transaction) {
                    return $callback($transaction);
                }
            );
    }
}
