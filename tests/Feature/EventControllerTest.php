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

class EventControllerTest extends TestCase
{
    /**
     * Exigir autenticación para crear eventos.
     */
    public function test_event_requires_authentication(): void
    {
        $this->mockUnusedServices();

        $this->postJson('/api/events', [])
            ->assertStatus(401)
            ->assertJsonPath(
                'message',
                'Debes proporcionar un token de autenticación.'
            );
    }

    /**
     * Impedir que los compradores creen eventos.
     */
    public function test_customer_cannot_create_events(): void
    {
        $this->mockUser('customer');

        $this->withToken('token-ficticio')
            ->postJson('/api/events', $this->eventData())
            ->assertStatus(403);
    }

    /**
     * Permitir que un vendedor cree un borrador.
     */
    public function test_seller_can_create_event(): void
    {
        $this->mockUser('seller', 'create');

        $this->withToken('token-ficticio')
            ->postJson('/api/events', $this->eventData())
            ->assertStatus(201)
            ->assertJsonPath(
                'message',
                'Evento registrado como borrador.'
            )
            ->assertJsonPath('event.id', 'evento-prueba')
            ->assertJsonPath('event.sellerId', 'firebase-uid-prueba')
            ->assertJsonPath('event.status', 'draft')
            ->assertJsonPath('event.name', 'Concierto de prueba');
    }

    /**
     * Permitir que un administrador cree eventos.
     */
    public function test_admin_can_create_event(): void
    {
        $this->mockUser('admin', 'create');

        $this->withToken('token-ficticio')
            ->postJson('/api/events', $this->eventData())
            ->assertStatus(201)
            ->assertJsonPath('event.status', 'draft');
    }

    /**
     * Rechazar eventos sin datos obligatorios.
     */
    public function test_event_rejects_missing_fields(): void
    {
        $this->mockUser('seller');

        $this->withToken('token-ficticio')
            ->postJson('/api/events', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'description',
                'venue',
                'timezone',
            ]);
    }

    /**
     * Impedir que el cliente asigne el propietario.
     */
    public function test_event_rejects_protected_fields(): void
    {
        $this->mockUser('seller');

        $data = $this->eventData();
        $data['sellerId'] = 'otro-usuario';
        $data['status'] = 'published';

        $this->withToken('token-ficticio')
            ->postJson('/api/events', $data)
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Se enviaron campos no autorizados.'
            );
    }

    /**
     * Exigir autenticación para editar eventos.
     */
    public function test_event_update_requires_authentication(): void
    {
        $this->mockUnusedServices();

        $this->patchJson('/api/events/evento-prueba', [
            'name' => 'Evento actualizado',
        ])
            ->assertStatus(401)
            ->assertJsonPath(
                'message',
                'Debes proporcionar un token de autenticación.'
            );
    }

    /**
     * Impedir cambios en campos protegidos.
     */
    public function test_event_update_rejects_protected_fields(): void
    {
        $this->mockUser('seller');

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba', [
                'sellerId' => 'otro-vendedor',
                'status' => 'published',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Se enviaron campos no autorizados.'
            );
    }

    /**
     * Permitir editar un evento propio.
     */
    public function test_seller_can_update_own_event(): void
    {
        $this->mockUser('seller', 'update-own');

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba', [
                'name' => '  Evento actualizado  ',
            ])
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Evento actualizado correctamente.'
            )
            ->assertJsonPath('event.id', 'evento-prueba')
            ->assertJsonPath('event.sellerId', 'firebase-uid-prueba')
            ->assertJsonPath('event.name', 'Evento actualizado')
            ->assertJsonPath('event.status', 'draft');
    }

    /**
     * Impedir editar eventos de otro vendedor.
     */
    public function test_seller_cannot_update_another_sellers_event(): void
    {
        $this->mockUser('seller', 'update-other');

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba', [
                'name' => 'Evento actualizado',
            ])
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'No tienes permisos para editar este evento.'
            );
    }

    /**
     * Permitir al administrador editar eventos ajenos.
     */
    public function test_admin_can_update_another_sellers_event(): void
    {
        $this->mockUser('admin', 'update-admin');

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-prueba', [
                'name' => '  Evento actualizado  ',
            ])
            ->assertStatus(200)
            ->assertJsonPath('event.name', 'Evento actualizado')
            ->assertJsonPath('event.sellerId', 'otro-vendedor');
    }

    /**
     * Rechazar la edición de eventos inexistentes.
     */
    public function test_event_update_rejects_missing_event(): void
    {
        $this->mockUser('seller', 'update-missing');

        $this->withToken('token-ficticio')
            ->patchJson('/api/events/evento-inexistente', [
                'name' => 'Evento actualizado',
            ])
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'El evento no existe.'
            );
    }

    /**
     * Preparar los datos de un evento.
     */
    private function eventData(): array
    {
        return [
            'name' => 'Concierto de prueba',
            'description' => 'Evento musical de prueba para BluePass.',
            'venue' => 'Auditorio de prueba',
            'timezone' => 'America/Mexico_City',
        ];
    }

    /**
     * Simular servicios sin autenticación.
     */
    private function mockUnusedServices(): void
    {
        $auth = Mockery::mock(Auth::class);
        $auth->shouldNotReceive('verifyIdToken');

        $firestore = Mockery::mock(FirestoreClient::class);
        $firestore->shouldNotReceive('collection');

        $this->app->instance(Auth::class, $auth);
        $this->app->instance(FirestoreClient::class, $firestore);
    }

    /**
     * Simular el usuario y sus permisos.
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

        $profile = [
            'name' => 'Usuario de prueba',
            'email' => 'usuario@ejemplo.com',
            'role' => $role,
            'status' => 'active',
            'authSubject' => $uid,
        ];

        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $snapshot->shouldReceive('data')
            ->once()
            ->andReturn($profile);

        $userDocument = Mockery::mock(DocumentReference::class);

        $userDocument->shouldReceive('snapshot')
            ->once()
            ->andReturn($snapshot);

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

        if ($action === 'create') {
            $this->mockEventCreation($firestore, $uid);
        } elseif (str_starts_with($action, 'update-')) {
            $this->mockEventUpdate($firestore, $uid, $action);
        } else {
            $firestore->shouldNotReceive('collection')
                ->with('events');
        }

        $this->app->instance(FirestoreClient::class, $firestore);
    }

    /**
     * Simular la creación de un evento.
     */
    private function mockEventCreation(
        $firestore,
        string $uid
    ): void {
        $document = Mockery::mock(DocumentReference::class);

        $document->shouldReceive('set')
            ->once()
            ->with(Mockery::on(function ($data) use ($uid) {
                return is_array($data)
                    && ($data['sellerId'] ?? null) === $uid
                    && ($data['status'] ?? null) === 'draft'
                    && ($data['name'] ?? null) === 'Concierto de prueba'
                    && isset($data['createdAt'])
                    && isset($data['updatedAt']);
            }))
            ->andReturn([]);

        $document->shouldReceive('id')
            ->once()
            ->andReturn('evento-prueba');

        $events = Mockery::mock(CollectionReference::class);

        $events->shouldReceive('newDocument')
            ->once()
            ->andReturn($document);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);
    }

    /**
     * Simular la consulta y edición de eventos.
     */
    private function mockEventUpdate(
        $firestore,
        string $uid,
        string $action
    ): void {
        $missing = $action === 'update-missing';
        $otherSeller = in_array(
            $action,
            ['update-other', 'update-admin'],
            true
        );

        $sellerId = $otherSeller ? 'otro-vendedor' : $uid;

        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn(!$missing);

        if (!$missing) {
            $snapshot->shouldReceive('data')
                ->once()
                ->andReturn([
                    'sellerId' => $sellerId,
                    'name' => 'Concierto de prueba',
                    'description' => 'Evento musical de prueba.',
                    'venue' => 'Auditorio de prueba',
                    'timezone' => 'America/Mexico_City',
                    'date' => null,
                    'endAt' => null,
                    'status' => 'draft',
                ]);
        } else {
            $snapshot->shouldNotReceive('data');
        }

        $document = Mockery::mock(DocumentReference::class);

        $document->shouldReceive('snapshot')
            ->once()
            ->andReturn($snapshot);

        if (in_array(
            $action,
            ['update-own', 'update-admin'],
            true
        )) {
            $document->shouldReceive('update')
                ->once()
                ->with(Mockery::on(function ($updates) {
                    if (!is_array($updates) || count($updates) !== 2) {
                        return false;
                    }

                    return $updates[0]['path'] === 'name'
                        && $updates[0]['value'] === 'Evento actualizado'
                        && $updates[1]['path'] === 'updatedAt'
                        && isset($updates[1]['value']);
                }))
                ->andReturn([]);
        } else {
            $document->shouldNotReceive('update');
        }

        $events = Mockery::mock(CollectionReference::class);

        $eventId = $missing
            ? 'evento-inexistente'
            : 'evento-prueba';

        $events->shouldReceive('document')
            ->once()
            ->with($eventId)
            ->andReturn($document);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);
    }
}
