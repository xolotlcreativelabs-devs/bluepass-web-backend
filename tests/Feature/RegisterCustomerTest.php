<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class RegisterCustomerTest extends TestCase
{
    /**
     * El registro requiere un token de Firebase.
     */
    public function test_register_requires_a_firebase_token(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Cliente Prueba',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idToken']);
    }

    /**
     * El nombre es obligatorio.
     */
    public function test_register_requires_a_name(): void
    {
        $this->postJson('/api/auth/register', [
            'idToken' => 'token-de-prueba',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * El nombre debe tener al menos dos caracteres.
     */
    public function test_register_rejects_a_short_name(): void
    {
        $this->postJson('/api/auth/register', [
            'idToken' => 'token-de-prueba',
            'name' => 'A',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * El nombre no puede superar los 100 caracteres.
     */
    public function test_register_rejects_a_name_longer_than_100_characters(): void
    {
        $this->postJson('/api/auth/register', [
            'idToken' => 'token-de-prueba',
            'name' => str_repeat('A', 101),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Rechazar tokens de Firebase inválidos.
     */
    public function test_register_rejects_an_invalid_firebase_token(): void
    {
        $this->postJson('/api/auth/register', [
            'idToken' => 'token-invalido-de-prueba',
            'name' => 'Cliente Prueba',
        ])
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Token de autenticación inválido, expirado o revocado.',
            ]);
    }

    /**
     * Rechazar tokens revocados sin crear un perfil.
     */
    public function test_register_rejects_a_revoked_firebase_token(): void
    {
        $token = Mockery::mock(UnencryptedToken::class);

        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-revocado-de-prueba', true)
            ->andThrow(new RevokedIdToken($token));

        // No consultar usuarios si el token ya fue rechazado.
        $auth->shouldNotReceive('getUser');

        $this->app->instance(Auth::class, $auth);

        $firestore = Mockery::mock(FirestoreClient::class);
        $firestore->shouldNotReceive('collection');

        $this->app->instance(FirestoreClient::class, $firestore);

        $this->postJson('/api/auth/register', [
            'idToken' => 'token-revocado-de-prueba',
            'name' => 'Cliente Prueba',
        ])
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Token de autenticación inválido, expirado o revocado.',
            ]);
    }

    /**
     * Crear un comprador con permisos seguros.
     */
    public function test_register_creates_a_customer_with_safe_permissions(): void
    {
        $uid = 'firebase-uid-nuevo';

        $this->mockFirebaseUser($uid);

        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn(false);

        $document = Mockery::mock(DocumentReference::class);

        $document->shouldReceive('snapshot')
            ->once()
            ->andReturn($snapshot);

        $document->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($profile) use ($uid) {
                return is_array($profile)
                    && $profile['name'] === 'Cliente Prueba'
                    && $profile['email'] === 'cliente@ejemplo.com'
                    && $profile['role'] === 'customer'
                    && $profile['status'] === 'active'
                    && $profile['authSubject'] === $uid
                    && isset($profile['createdAt'])
                    && isset($profile['updatedAt']);
            }))
            ->andReturn([]);

        $this->mockFirestore($uid, $document);

        $this->postJson('/api/auth/register', [
            'idToken' => 'token-ficticio',
            'name' => 'Cliente Prueba',
            'role' => 'admin',
            'status' => 'suspended',
        ])
            ->assertStatus(201)
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.name', 'Cliente Prueba')
            ->assertJsonPath('emailVerificationRequired', true);
    }

    /**
     * No permitir registros duplicados.
     */
    public function test_register_rejects_an_existing_customer(): void
    {
        $uid = 'firebase-uid-existente';

        $this->mockFirebaseUser($uid);

        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $document = Mockery::mock(DocumentReference::class);

        $document->shouldReceive('snapshot')
            ->once()
            ->andReturn($snapshot);

        $document->shouldNotReceive('create');

        $this->mockFirestore($uid, $document);

        $this->postJson('/api/auth/register', [
            'idToken' => 'token-ficticio',
            'name' => 'Cliente Existente',
        ])
            ->assertStatus(409)
            ->assertJson([
                'message' => 'El usuario ya está registrado en BluePass.',
            ]);
    }

    /**
     * Simular un usuario de Firebase Authentication.
     */
    private function mockFirebaseUser(string $uid): void
    {
        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->once()
            ->andReturn(new DataSet([
                'sub' => $uid,
            ], ''));

        $user = UserRecord::fromResponseData([
            'localId' => $uid,
            'email' => 'cliente@ejemplo.com',
            'emailVerified' => false,
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
    }

    /**
     * Simular Firestore sin modificar la base de datos real.
     */
    private function mockFirestore(
        string $uid,
        DocumentReference $document
    ): void {
        $collection = Mockery::mock(CollectionReference::class);

        $collection->shouldReceive('document')
            ->once()
            ->with($uid)
            ->andReturn($document);

        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('users')
            ->andReturn($collection);

        $this->app->instance(FirestoreClient::class, $firestore);
    }
}
