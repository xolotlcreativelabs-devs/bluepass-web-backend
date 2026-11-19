<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
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

class EventStatusPermissionsTest extends TestCase
{
    /**
     * Un vendedor no puede publicar eventos ajenos.
     */
    public function test_seller_cannot_publish_another_sellers_event(): void
    {
        $this->mockUserAndEvent(
            'seller',
            false,
            'draft',
            'published'
        );

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'No tienes permisos sobre este evento.'
            );
    }

    /**
     * Un vendedor no puede despublicar eventos ajenos.
     */
    public function test_seller_cannot_unpublish_another_sellers_event(): void
    {
        $this->mockUserAndEvent(
            'seller',
            false,
            'published',
            'unpublished'
        );

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'unpublished',
            ])
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'No tienes permisos sobre este evento.'
            );
    }

    /**
     * Un administrador puede publicar eventos ajenos.
     */
    public function test_admin_can_publish_another_sellers_event(): void
    {
        $this->mockUserAndEvent(
            'admin',
            true,
            'draft',
            'published'
        );

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Evento publicado correctamente.'
            )
            ->assertJsonPath('event.id', 'evento-prueba')
            ->assertJsonPath('event.status', 'published');
    }

    /**
     * Un administrador puede despublicar eventos ajenos.
     */
    public function test_admin_can_unpublish_another_sellers_event(): void
    {
        $this->mockUserAndEvent(
            'admin',
            true,
            'published',
            'unpublished'
        );

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'unpublished',
            ])
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Evento despublicado correctamente.'
            )
            ->assertJsonPath('event.id', 'evento-prueba')
            ->assertJsonPath('event.status', 'unpublished');
    }

    /**
     * Un comprador no puede publicar eventos.
     */
    public function test_customer_cannot_publish_events(): void
    {
        $this->mockUserAndEvent(
            'customer',
            false,
            'draft',
            'published'
        );

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(403);
    }

    /**
     * Un comprador no puede despublicar eventos.
     */
    public function test_customer_cannot_unpublish_events(): void
    {
        $this->mockUserAndEvent(
            'customer',
            false,
            'published',
            'unpublished'
        );

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'unpublished',
            ])
            ->assertStatus(403);
    }

    /**
     * Simular usuario autenticado y permisos sobre un evento.
     */
    private function mockUserAndEvent(
        string $role,
        bool $canUpdate,
        string $currentStatus,
        string $requestedStatus
    ): void {
        $uid = 'firebase-uid-prueba';

        // Simular el token de Firebase.
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

        if ($role === 'customer') {
            // Los compradores no deben acceder al evento.
            $firestore->shouldNotReceive('collection')
                ->with('events');

            $firestore->shouldNotReceive('collection')
                ->with('audit_logs');

            $firestore->shouldNotReceive('runTransaction');

            $this->app->instance(
                FirestoreClient::class,
                $firestore
            );

            return;
        }

        // Simular un evento perteneciente a otro vendedor.
        $eventSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $eventSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $eventSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'sellerId' => 'otro-vendedor',
                'status' => $currentStatus,
                'name' => 'Evento de otro vendedor',
                'date' => new Timestamp(
                    new DateTimeImmutable(
                        '2026-12-10T18:00:00-06:00'
                    )
                ),
                'endAt' => new Timestamp(
                    new DateTimeImmutable(
                        '2026-12-10T22:00:00-06:00'
                    )
                ),
            ]);

        $eventDocument = Mockery::mock(
            DocumentReference::class
        );

        // Todas las modificaciones deben hacerse en la transacción.
        $eventDocument->shouldNotReceive('update');

        if ($canUpdate) {
            // El ID se utiliza en la auditoría y en la respuesta.
            $eventDocument->shouldReceive('id')
                ->twice()
                ->andReturn('evento-prueba');
        }

        $events = Mockery::mock(
            CollectionReference::class
        );

        $events->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($eventDocument);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        // Simular la transacción de Firestore.
        $transaction = Mockery::mock(
            Transaction::class
        );

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($eventDocument)
            ->andReturn($eventSnapshot);

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

            // Verificar la actualización del estado.
            $transaction->shouldReceive('update')
                ->once()
                ->with(
                    $eventDocument,
                    Mockery::on(
                        function ($updates) use ($requestedStatus) {
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
                                && ($fields['status'] ?? null)
                                    === $requestedStatus
                                && isset($fields['updatedAt']);
                        }
                    )
                );

            // Comprobar que el administrador sea el autor de la acción.
            $transaction->shouldReceive('create')
                ->once()
                ->with(
                    $auditDocument,
                    Mockery::on(
                        function ($data) use ($requestedStatus, $uid) {
                            if (!is_array($data)) {
                                return false;
                            }

                            return count($data) === 7
                                && ($data['action'] ?? null)
                                    === $requestedStatus
                                && ($data['resourceType'] ?? null)
                                    === 'events'
                                && ($data['resourceId'] ?? null)
                                    === 'evento-prueba'
                                && ($data['actorId'] ?? null) === $uid
                                && ($data['actorRole'] ?? null)
                                    === 'admin'
                                && ($data['changedFields'] ?? null)
                                    === ['status']
                                && isset($data['createdAt']);
                        }
                    )
                );
        } else {
            // Un vendedor no puede modificar ni auditar eventos ajenos.
            $transaction->shouldNotReceive('update');
            $transaction->shouldNotReceive('create');

            $firestore->shouldNotReceive('collection')
                ->with('audit_logs');
        }

        $firestore->shouldReceive('runTransaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(
                function ($callback) use ($transaction) {
                    return $callback($transaction);
                }
            );

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }
}
