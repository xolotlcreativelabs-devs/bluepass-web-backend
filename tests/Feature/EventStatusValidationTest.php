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
use RuntimeException;
use Tests\TestCase;

class EventStatusValidationTest extends TestCase
{
    /**
     * Rechazar una solicitud sin estado.
     */
    public function test_status_is_required(): void
    {
        $this->mockSeller();
        $this->mockFirestoreWithoutEventQueries();

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'El estado solicitado no es válido.'
            )
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * Impedir regresar manualmente al estado borrador.
     */
    public function test_status_rejects_draft_value(): void
    {
        $this->mockSeller();
        $this->mockFirestoreWithoutEventQueries();

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'draft',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * Rechazar estados que no están permitidos.
     */
    public function test_status_rejects_unknown_value(): void
    {
        $this->mockSeller();
        $this->mockFirestoreWithoutEventQueries();

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'cancelled',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * Impedir modificar otros campos junto con el estado.
     */
    public function test_status_rejects_extra_fields(): void
    {
        $this->mockSeller();
        $this->mockFirestoreWithoutEventQueries();

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'published',
                'sellerId' => 'otro-vendedor',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Se enviaron campos no autorizados.'
            );
    }

    /**
     * Responder cuando el evento no existe.
     */
    public function test_status_rejects_missing_event(): void
    {
        $this->mockSeller();
        $this->mockEventTransaction('missing');

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Controlar un error de la transacción de Firestore.
     */
    public function test_status_handles_firestore_failure(): void
    {
        $this->mockSeller();
        $this->mockEventTransaction('failure');

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible cambiar el estado del evento.'
            );
    }

    /**
     * Simular un vendedor activo autenticado.
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
     * Comprobar que una validación incorrecta no consulte eventos.
     */
    private function mockFirestoreWithoutEventQueries(): void
    {
        $firestore = $this->app->make(
            FirestoreClient::class
        );

        $firestore->shouldNotReceive('runTransaction');
        $firestore->shouldNotReceive('collection')
            ->with('events');
    }

    /**
     * Simular un evento inexistente o un error de Firestore.
     */
    private function mockEventTransaction(string $scenario): void
    {
        $firestore = $this->app->make(
            FirestoreClient::class
        );

        $document = Mockery::mock(
            DocumentReference::class
        );

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

        if ($scenario === 'failure') {
            $firestore->shouldReceive('runTransaction')
                ->once()
                ->with(Mockery::type('callable'))
                ->andThrow(
                    new RuntimeException(
                        'Error simulado de Firestore.'
                    )
                );

            return;
        }

        $snapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn(false);

        $transaction = Mockery::mock(
            Transaction::class
        );

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($document)
            ->andReturn($snapshot);

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