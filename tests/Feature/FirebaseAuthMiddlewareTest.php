<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class FirebaseAuthMiddlewareTest extends TestCase
{
    /**
     * Crear rutas de prueba protegidas por roles.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('firebase.auth:admin')
            ->get('/api/test-admin', function (Request $request) {
                return response()->json([
                    'uid' => $request->attributes->get('firebase_uid'),
                    'role' => $request->attributes->get('firebase_role'),
                ]);
            });

        Route::middleware('firebase.auth:customer')
            ->get('/api/test-customer', function (Request $request) {
                return response()->json([
                    'uid' => $request->attributes->get('firebase_uid'),
                    'role' => $request->attributes->get('firebase_role'),
                ]);
            });

        Route::middleware('firebase.auth:seller,staff')
            ->get('/api/test-event-access', function (Request $request) {
                return response()->json([
                    'role' => $request->attributes->get('firebase_role'),
                ]);
            });
    }

    /**
     * Rechazar solicitudes sin token.
     */
    public function test_middleware_requires_token(): void
    {
        $this->mockUnusedServices();

        $this->getJson('/api/test-admin')
            ->assertStatus(401);
    }

    /**
     * Rechazar tokens inválidos.
     */
    public function test_middleware_rejects_invalid_token(): void
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
            ->getJson('/api/test-admin')
            ->assertStatus(401)
            ->assertJsonPath(
                'message',
                'Token de autenticación inválido, expirado o revocado.'
            );
    }

    /**
     * Rechazar tokens revocados.
     */
    public function test_middleware_rejects_revoked_token(): void
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
            ->getJson('/api/test-admin')
            ->assertStatus(401);
    }

    /**
     * Rechazar tokens sin un UID válido.
     */
    public function test_middleware_rejects_empty_uid(): void
    {
        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->once()
            ->andReturn(new DataSet([
                'sub' => '',
            ], ''));

        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-sin-uid', true)
            ->andReturn($token);

        $auth->shouldNotReceive('getUser');

        $this->app->instance(Auth::class, $auth);

        $firestore = Mockery::mock(FirestoreClient::class);
        $firestore->shouldNotReceive('collection');

        $this->app->instance(FirestoreClient::class, $firestore);

        $this->withToken('token-sin-uid')
            ->getJson('/api/test-admin')
            ->assertStatus(401)
            ->assertJsonPath(
                'message',
                'No se pudo identificar al usuario.'
            );
    }

    /**
     * Permitir al comprador acceder a su ruta.
     */
    public function test_customer_can_access_customer_route(): void
    {
        $this->mockUser('customer');

        $this->withToken('token-ficticio')
            ->getJson('/api/test-customer')
            ->assertStatus(200)
            ->assertJsonPath('role', 'customer')
            ->assertJsonPath('uid', 'firebase-uid-prueba');
    }

    /**
     * Impedir que un comprador acceda como administrador.
     */
    public function test_customer_cannot_access_admin_route(): void
    {
        $this->mockUser('customer');

        $this->withToken('token-ficticio')
            ->getJson('/api/test-admin')
            ->assertStatus(403);
    }

    /**
     * Permitir el acceso al administrador.
     */
    public function test_admin_can_access_admin_route(): void
    {
        $this->mockUser('admin');

        $this->withToken('token-ficticio')
            ->getJson('/api/test-admin')
            ->assertStatus(200)
            ->assertJsonPath('role', 'admin');
    }

    /**
     * Permitir el acceso al vendedor.
     */
    public function test_seller_can_access_authorized_route(): void
    {
        $this->mockUser('seller');

        $this->withToken('token-ficticio')
            ->getJson('/api/test-event-access')
            ->assertStatus(200)
            ->assertJsonPath('role', 'seller');
    }

    /**
     * Permitir el acceso al personal autorizado.
     */
    public function test_staff_can_access_authorized_route(): void
    {
        $this->mockUser('staff');

        $this->withToken('token-ficticio')
            ->getJson('/api/test-event-access')
            ->assertStatus(200)
            ->assertJsonPath('role', 'staff');
    }

    /**
     * Bloquear perfiles suspendidos.
     */
    public function test_suspended_user_cannot_access(): void
    {
        $this->mockUser('admin', 'suspended');

        $this->withToken('token-ficticio')
            ->getJson('/api/test-admin')
            ->assertStatus(403);
    }

    /**
     * Bloquear cuentas deshabilitadas en Firebase.
     */
    public function test_disabled_firebase_user_cannot_access(): void
    {
        $this->mockUser(
            role: 'admin',
            disabled: true
        );

        $this->withToken('token-ficticio')
            ->getJson('/api/test-admin')
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'La cuenta está deshabilitada.'
            );
    }

    /**
     * Bloquear cuentas con correo sin verificar.
     */
    public function test_unverified_email_cannot_access(): void
    {
        $this->mockUser(
            role: 'customer',
            emailVerified: false
        );

        $this->withToken('token-ficticio')
            ->getJson('/api/test-customer')
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'Debes verificar tu correo electrónico.'
            );
    }

    /**
     * Rechazar perfiles que no coincidan con la identidad.
     */
    public function test_middleware_rejects_mismatched_profile(): void
    {
        $this->mockUser(
            'admin',
            'active',
            'otro-firebase-uid'
        );

        $this->withToken('token-ficticio')
            ->getJson('/api/test-admin')
            ->assertStatus(403);
    }

    /**
     * Rechazar roles desconocidos.
     */
    public function test_middleware_rejects_unknown_role(): void
    {
        $this->mockUser('superadmin');

        $this->withToken('token-ficticio')
            ->getJson('/api/test-admin')
            ->assertStatus(403);
    }

    /**
     * Proteger la actualización del perfil sin token.
     */
    public function test_profile_update_requires_token(): void
    {
        $this->mockUnusedServices();

        $this->patchJson('/api/users/me', [
            'name' => 'Nombre No Autorizado',
        ])
            ->assertStatus(401);
    }

    /**
     * Proteger la actualización del perfil con token inválido.
     */
    public function test_profile_update_rejects_invalid_token(): void
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
            ->patchJson('/api/users/me', [
                'name' => 'Nombre No Autorizado',
            ])
            ->assertStatus(401);
    }

    /**
     * Simular servicios cuando no deben utilizarse.
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
     * Simular Firebase y Firestore con un usuario de prueba.
     */
    private function mockUser(
        string $role,
        string $status = 'active',
        ?string $authSubject = null,
        bool $disabled = false,
        bool $emailVerified = true
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
            'email' => 'cliente@ejemplo.com',
            'emailVerified' => $emailVerified,
            'disabled' => $disabled,
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

        // No consultar Firestore si Firebase rechaza la cuenta.
        if ($disabled || !$emailVerified) {
            $firestore = Mockery::mock(FirestoreClient::class);
            $firestore->shouldNotReceive('collection');

            $this->app->instance(FirestoreClient::class, $firestore);

            return;
        }

        $profile = [
            'name' => 'Usuario Prueba',
            'email' => 'cliente@ejemplo.com',
            'role' => $role,
            'status' => $status,
            'authSubject' => $authSubject ?? $uid,
        ];

        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $snapshot->shouldReceive('data')
            ->once()
            ->andReturn($profile);

        $document = Mockery::mock(DocumentReference::class);

        $document->shouldReceive('snapshot')
            ->once()
            ->andReturn($snapshot);

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
