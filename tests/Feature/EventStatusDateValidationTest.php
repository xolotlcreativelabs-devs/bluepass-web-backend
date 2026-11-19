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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventStatusDateValidationTest extends TestCase
{
    /**
     * Rechazar eventos con fechas o zona horaria inválidas.
     */
    #[DataProvider('invalidEventData')]
    public function test_event_cannot_be_published_with_invalid_data(
        string $scenario
    ): void {
        $this->mockSeller();

        $event = [
            'sellerId' => 'firebase-uid-prueba',
            'status' => 'draft',
            'name' => 'Evento de prueba',
            'timezone' => 'America/Mexico_City',
            'date' => new Timestamp(
                new DateTimeImmutable('2026-12-10T18:00:00-06:00')
            ),
            'endAt' => new Timestamp(
                new DateTimeImmutable('2026-12-10T22:00:00-06:00')
            ),
        ];

        // Preparar los datos incorrectos de cada escenario.
        switch ($scenario) {
            case 'missing-both':
                $event['date'] = null;
                $event['endAt'] = null;
                break;

            case 'missing-end':
                $event['endAt'] = null;
                break;

            case 'end-before-start':
                $event['endAt'] = new Timestamp(
                    new DateTimeImmutable('2026-12-10T17:00:00-06:00')
                );
                break;

            case 'equal-dates':
                $event['endAt'] = new Timestamp(
                    new DateTimeImmutable('2026-12-10T18:00:00-06:00')
                );
                break;

            case 'missing-timezone':
                unset($event['timezone']);
                break;

            case 'invalid-timezone':
                $event['timezone'] = 'America/ZonaInexistente';
                break;

            case 'invalid-timezone-type':
                $event['timezone'] = 123;
                break;
        }

        $this->mockEvent($event);

        // No permitir la publicación con datos inválidos.
        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba/status', [
                'status' => 'published',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'El evento necesita fechas de inicio y ' .
                'finalización válidas para publicarse.'
            );
    }

    /**
     * Escenarios que deben rechazarse.
     */
    public static function invalidEventData(): array
    {
        return [
            'sin fechas' => ['missing-both'],
            'sin fecha final' => ['missing-end'],
            'fecha final anterior' => ['end-before-start'],
            'fechas iguales' => ['equal-dates'],
            'sin zona horaria' => ['missing-timezone'],
            'zona horaria inexistente' => ['invalid-timezone'],
            'zona horaria con tipo incorrecto' => [
                'invalid-timezone-type',
            ],
        ];
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

        // Consultar el perfil del vendedor.
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
     * Simular el evento dentro de una transacción.
     */
    private function mockEvent(array $event): void
    {
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
            ->andReturn($event);

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

        $transaction = Mockery::mock(
            Transaction::class
        );

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($document)
            ->andReturn($snapshot);

        // No debe haber escrituras para eventos inválidos.
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
