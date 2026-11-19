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

class EventStatusControllerTest extends TestCase
{
    /**
     * Publicar un evento que está en borrador.
     */
    public function test_seller_can_publish_draft_event(): void
    {
        $this->mockSeller();
        $this->mockEvent('draft', 'published');

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
     * Despublicar un evento publicado.
     */
    public function test_seller_can_unpublish_event(): void
    {
        $this->mockSeller();
        $this->mockEvent('published', 'unpublished');

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'unpublished',
            ])
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Evento despublicado correctamente.'
            )
            ->assertJsonPath('event.status', 'unpublished');
    }

    /**
     * Permitir despublicar aunque falten las fechas.
     */
    public function test_seller_can_unpublish_event_without_dates(): void
    {
        $this->mockSeller();
        $this->mockEvent('published', 'unpublished', false);

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'unpublished',
            ])
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Evento despublicado correctamente.'
            )
            ->assertJsonPath('event.status', 'unpublished');
    }

    /**
     * Volver a publicar un evento despublicado.
     */
    public function test_seller_can_republish_event(): void
    {
        $this->mockSeller();
        $this->mockEvent('unpublished', 'published');

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(200)
            ->assertJsonPath('event.status', 'published');
    }

    /**
     * Impedir publicar dos veces un evento.
     */
    public function test_event_rejects_same_status(): void
    {
        $this->mockSeller();
        $this->mockEvent('published', null);

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'El evento ya tiene ese estado.'
            );
    }

    /**
     * Impedir despublicar un borrador.
     */
    public function test_draft_cannot_be_unpublished(): void
    {
        $this->mockSeller();
        $this->mockEvent('draft', null);

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'unpublished',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'No se permite este cambio de estado.'
            );
    }

    /**
     * Rechazar solicitudes sin autenticación.
     */
    public function test_status_requires_authentication(): void
    {
        $this->patchJson(
            '/api/events/evento-prueba/status',
            ['status' => 'published']
        )->assertStatus(401);
    }

    /**
     * Simular autenticación de un vendedor activo.
     */
    private function mockSeller(): void
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
            'email' => 'vendedor@ejemplo.com',
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
                'name' => 'Vendedor de prueba',
                'email' => 'vendedor@ejemplo.com',
                'role' => 'seller',
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
     * Simular un evento y su transacción de cambio de estado.
     */
    private function mockEvent(
        string $currentStatus,
        ?string $newStatus,
        bool $withDates = true
    ): void {
        $firestore = $this->app->make(
            FirestoreClient::class
        );

        // Incluir fechas válidas tal como las guarda Firestore.
        $event = [
            'sellerId' => 'firebase-uid-prueba',
            'status' => $currentStatus,
            'name' => 'Evento de prueba',
            'date' => new Timestamp(
                new DateTimeImmutable('2026-12-10T18:00:00-06:00')
            ),
            'endAt' => new Timestamp(
                new DateTimeImmutable('2026-12-10T22:00:00-06:00')
            ),
        ];

        // Simular un evento que no tiene fechas.
        if (!$withDates) {
            $event['date'] = null;
            $event['endAt'] = null;
        }

        $snapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $snapshot->shouldReceive('data')
            ->once()
            ->andReturn($event);

        $document = Mockery::mock(
            DocumentReference::class
        );

        if ($newStatus !== null) {
            $document->shouldReceive('id')
                ->once()
                ->andReturn('evento-prueba');
        }

        // Las modificaciones deben realizarse en la transacción.
        $document->shouldNotReceive('update');

        $events = Mockery::mock(
            CollectionReference::class
        );

        $events->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($document);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        $transaction = Mockery::mock(
            Transaction::class
        );

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($document)
            ->andReturn($snapshot);

        if ($newStatus !== null) {
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

                            return ($fields['status'] ?? null) === $newStatus
                                && isset($fields['updatedAt']);
                        }
                    )
                );
        } else {
            // No debe haber cambios para estados rechazados.
            $transaction->shouldNotReceive('update');
        }

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
