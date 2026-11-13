<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    /**
     * Exigir autenticación para consultar el perfil.
     */
    public function test_profile_requires_a_token(): void
    {
        $this->mockUnusedServices();

        $this->getJson('/api/users/me')
            ->assertStatus(401);
    }

    /**
     * Rechazar tokens inválidos.
     */
    public function test_profile_rejects_an_invalid_token(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-invalido', true)
            ->andThrow(new FailedToVerifyToken('Token inválido.'));

        $auth->shouldNotReceive('getUser');

        $this->app->instance(Auth::class, $auth);

        $firestore = Mockery::mock(FirestoreClient::class);
        $firestore->shouldNotReceive('collection');

        $this->app->instance(FirestoreClient::class, $firestore);

        $this->withToken('token-invalido')
            ->getJson('/api/users/me')
            ->assertStatus(401)
            ->assertJsonPath(
                'message',
                'Token de autenticación inválido, expirado o revocado.'
            );
    }

    /**
     * Rechazar tokens revocados.
     */
    public function test_profile_rejects_a_revoked_token(): void
    {
        $token = Mockery::mock(UnencryptedToken::class);

        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-revocado', true)
            ->andThrow(new RevokedIdToken($token));

        $auth->shouldNotReceive('getUser');

        $this->app->instance(Auth::class, $auth);

        $firestore = Mockery::mock(FirestoreClient::class);
        $firestore->shouldNotReceive('collection');

        $this->app->instance(FirestoreClient::class, $firestore);

        $this->withToken('token-revocado')
            ->getJson('/api/users/me')
            ->assertStatus(401);
    }

    /**
     * Consultar el perfil propio.
     */
    public function test_user_can_view_own_profile(): void
    {
        $this->mockUser();

        $this->withToken('token-ficticio')
            ->getJson('/api/users/me')
            ->assertStatus(200)
            ->assertJsonPath('user.id', 'firebase-uid-prueba')
            ->assertJsonPath('user.name', 'Cliente Prueba')
            ->assertJsonPath('user.email', 'cliente@ejemplo.com')
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonPath('user.status', 'active');
    }

    /**
     * Actualizar únicamente el nombre.
     */
    public function test_user_can_update_own_name(): void
    {
        $document = $this->mockUser(expectUpdate: true);

        $document->shouldReceive('update')
            ->once()
            ->with(Mockery::on(function ($updates) {
                if (!is_array($updates) || count($updates) !== 2) {
                    return false;
                }

                $paths = array_column($updates, 'path');

                return $paths === ['name', 'updatedAt']
                    && $updates[0]['value'] === 'Nombre Actualizado'
                    && isset($updates[1]['value']);
            }))
            ->andReturn([]);

        $this->withToken('token-ficticio')
            ->patchJson('/api/users/me', [
                'name' => '  Nombre Actualizado  ',
            ])
            ->assertStatus(200)
            ->assertJsonPath('user.name', 'Nombre Actualizado')
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonPath('user.status', 'active');
    }

    /**
     * Impedir cambios en campos protegidos.
     */
    public function test_user_cannot_change_protected_fields(): void
    {
        $this->mockUser();

        $this->withToken('token-ficticio')
            ->patchJson('/api/users/me', [
                'name' => 'Cliente Prueba',
                'role' => 'admin',
                'status' => 'active',
                'authSubject' => 'otro-uid',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Se enviaron campos no autorizados.'
            );
    }

    /**
     * Bloquear cuentas suspendidas.
     */
    public function test_suspended_user_cannot_view_profile(): void
    {
        $this->mockUser(status: 'suspended');

        $this->withToken('token-ficticio')
            ->getJson('/api/users/me')
            ->assertStatus(403);
    }

    /**
     * Impedir el acceso a perfiles de otra identidad.
     */
    public function test_user_cannot_access_mismatched_profile(): void
    {
        $this->mockUser(
            authSubject: 'firebase-uid-diferente'
        );

        $this->withToken('token-ficticio')
            ->getJson('/api/users/me')
            ->assertStatus(403);
    }

    /**
     * Rechazar nombres inválidos.
     */
    public function test_profile_rejects_invalid_name(): void
    {
        $this->mockUser();

        $this->withToken('token-ficticio')
            ->patchJson('/api/users/me', [
                'name' => 'A',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Rechazar perfiles inexistentes.
     */
    public function test_profile_rejects_missing_user(): void
    {
        $this->mockUser(profileExists: false);

        $this->withToken('token-ficticio')
            ->getJson('/api/users/me')
            ->assertStatus(404);
    }

    /**
     * Simular servicios que no deben utilizarse.
     */
    private function mockUnusedServices(): void
    {
        $auth = Mockery::mock(Auth::class);
        $auth->shouldNotReceive('verifyIdToken');

        $this->app->instance(Auth::class, $auth);

        $firestore = Mockery::mock(FirestoreClient::class);
        $firestore->shouldNotReceive('collection');

        $this->app->instance(FirestoreClient::class, $firestore);
    }

    /**
     * Simular Firebase Authentication y Firestore.
     */
    private function mockUser(
        string $status = 'active',
        ?string $authSubject = null,
        bool $expectUpdate = false,
        bool $profileExists = true
    ): DocumentReference {
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

        $profile = [
            'name' => 'Cliente Prueba',
            'email' => 'cliente@ejemplo.com',
            'role' => 'customer',
            'status' => $status,
            'authSubject' => $authSubject ?? $uid,
        ];

        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn($profileExists);

        if ($profileExists) {
            $snapshot->shouldReceive('data')
                ->once()
                ->andReturn($profile);
        } else {
            $snapshot->shouldNotReceive('data');
        }

        $document = Mockery::mock(DocumentReference::class);

        $document->shouldReceive('snapshot')
            ->once()
            ->andReturn($snapshot);

        if (!$expectUpdate) {
            $document->shouldNotReceive('update');
        }

        $collection = Mockery::mock(CollectionReference::class);

        $calls = $expectUpdate ? 2 : 1;

        $collection->shouldReceive('document')
            ->times($calls)
            ->with($uid)
            ->andReturn($document);

        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldReceive('collection')
            ->times($calls)
            ->with('users')
            ->andReturn($collection);

        $this->app->instance(FirestoreClient::class, $firestore);

        return $document;
    }
}
