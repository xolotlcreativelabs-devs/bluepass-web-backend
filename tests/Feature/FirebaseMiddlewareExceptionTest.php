<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Route;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FirebaseMiddlewareExceptionTest extends TestCase
{
    /**
     * Comprobar que el middleware no capture errores del controlador.
     */
    public function test_middleware_does_not_capture_controller_exceptions(): void
    {
        // Crear una ruta de prueba que provoque un error.
        Route::middleware('firebase.auth')
            ->get('/api/test-controller-exception', function () {
                throw new RuntimeException(
                    'Error de prueba del controlador.'
                );
            });

        $this->mockAuthenticatedUser();

        // Permitir que la excepción llegue a la prueba.
        $this->withoutExceptionHandling();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Error de prueba del controlador.'
        );

        $this->withToken('token-ficticio')
            ->getJson('/api/test-controller-exception');
    }

    /**
     * Simular un usuario autenticado y activo.
     */
    private function mockAuthenticatedUser(): void
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

        // Simular el perfil registrado en Firestore.
        $profile = [
            'name' => 'Cliente Prueba',
            'email' => 'cliente@ejemplo.com',
            'role' => 'customer',
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
