<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class EventValidationEdgeCasesTest extends TestCase
{
    /**
     * Rechazar nombres compuestos solo por espacios.
     */
    public function test_rejects_blank_event_name(): void
    {
        $this->mockSeller();

        $data = $this->validEvent();
        $data['name'] = '     ';

        $this->withToken('token-ficticio')
            ->postJson('/api/events', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Rechazar categorías vacías.
     */
    public function test_rejects_blank_category(): void
    {
        $this->mockSeller();

        $data = $this->validEvent();
        $data['category'] = '    ';

        $this->withToken('token-ficticio')
            ->postJson('/api/events', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    /**
     * Rechazar zonas horarias inexistentes.
     */
    public function test_rejects_invalid_timezone(): void
    {
        $this->mockSeller();

        $data = $this->validEvent();
        $data['timezone'] = 'Zona/Inexistente';

        $this->withToken('token-ficticio')
            ->postJson('/api/events', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['timezone']);
    }

    /**
     * Exigir la fecha de inicio si se proporciona el fin.
     */
    public function test_rejects_end_without_start(): void
    {
        $this->mockSeller();

        $data = $this->validEvent();
        $data['endAt'] = '2027-05-20T23:00:00-06:00';

        $this->withToken('token-ficticio')
            ->postJson('/api/events', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    /**
     * Exigir la fecha de finalización si se proporciona el inicio.
     */
    public function test_rejects_start_without_end(): void
    {
        $this->mockSeller();

        $data = $this->validEvent();
        $data['date'] = '2027-05-20T20:00:00-06:00';

        $this->withToken('token-ficticio')
            ->postJson('/api/events', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['endAt']);
    }

    /**
     * Rechazar nombres inválidos durante la edición.
     */
    public function test_rejects_invalid_name_on_update(): void
    {
        $this->mockSeller();

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba', [
                'name' => 'A',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Rechazar formatos incorrectos durante la edición.
     */
    public function test_rejects_invalid_date_on_update(): void
    {
        $this->mockSeller();

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba', [
                'date' => '20/05/2027',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    /**
     * No permitir actualizaciones sin información.
     */
    public function test_rejects_empty_update(): void
    {
        $this->mockSeller();

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba', [])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Debes proporcionar datos para actualizar.'
            );
    }

    /**
     * Datos válidos para un borrador.
     */
    private function validEvent(): array
    {
        return [
            'name' => 'Concierto de prueba',
            'description' => 'Evento musical de prueba para BluePass.',
            'category' => 'Música',
            'venue' => 'Auditorio de prueba',
            'timezone' => 'America/Mexico_City',
        ];
    }

    /**
     * Simular un vendedor autorizado de Firebase.
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

        $users = Mockery::mock(CollectionReference::class);

        $users->shouldReceive('document')
            ->once()
            ->with($uid)
            ->andReturn($userDocument);

        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('users')
            ->andReturn($users);

        // Las solicitudes inválidas no deben acceder a eventos.
        $firestore->shouldNotReceive('collection')
            ->with('events');

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }
}
