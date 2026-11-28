<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DeleteAccountController;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DeleteAccountLifecycleTest extends TestCase
{
    private FirestoreClient $firestore;

    protected function setUp(): void
    {
        parent::setUp();

        // Estas pruebas solo pueden utilizar Firestore Emulator.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->markTestSkipped(
                'Se requiere Firestore Emulator de BluePass.'
            );
        }

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);
    }

    public function test_deletes_account_without_related_records(): void
    {
        $uid = $this->newTestUid();

        $userDocument = $this->firestore
            ->collection('users')
            ->document($uid);

        try {
            $userDocument->set($this->userData($uid));

            $auth = $this->mockAuthentication($uid);

            // Simular la eliminacion de Firebase Authentication.
            $auth->shouldReceive('deleteUser')
                ->once()
                ->with($uid);

            $response = $this->executeDeletion($uid, $auth);

            $this->assertSame(200, $response->getStatusCode());

            $responseData = json_decode(
                $response->getContent(),
                true
            );

            $this->assertSame(
                'Cuenta eliminada correctamente.',
                $responseData['message'] ?? null
            );

            $this->assertFalse(
                $userDocument->snapshot()->exists()
            );
        } finally {
            $userDocument->delete();
        }
    }

    public function test_keeps_account_blocked_when_authentication_deletion_fails(): void
    {
        $uid = $this->newTestUid();

        $userDocument = $this->firestore
            ->collection('users')
            ->document($uid);

        try {
            $userDocument->set($this->userData($uid));

            $auth = $this->mockAuthentication($uid);

            $auth->shouldReceive('deleteUser')
                ->once()
                ->with($uid)
                ->andThrow(
                    new RuntimeException('Fallo simulado de Firebase Auth')
                );

            // No es posible confirmar si la identidad sigue existiendo.
            $auth->shouldReceive('getUser')
                ->once()
                ->with($uid)
                ->andThrow(
                    new RuntimeException(
                        'No se pudo consultar Firebase Authentication'
                    )
                );

            $response = $this->executeDeletion($uid, $auth);

            $this->assertSame(500, $response->getStatusCode());

            $snapshot = $userDocument->snapshot();

            $this->assertTrue($snapshot->exists());

            // Ante un resultado incierto, mantener la cuenta bloqueada.
            $this->assertSame(
                'deletion_pending',
                $snapshot->data()['status'] ?? null
            );
        } finally {
            $userDocument->delete();
        }
    }

    public function test_restores_account_when_authentication_deletion_fails_but_identity_exists(): void
    {
        $uid = $this->newTestUid();

        $userDocument = $this->firestore
            ->collection('users')
            ->document($uid);

        try {
            $userDocument->set($this->userData($uid));

            $auth = $this->mockAuthentication($uid);

            // Simular un error al eliminar la identidad.
            $auth->shouldReceive('deleteUser')
                ->once()
                ->with($uid)
                ->andThrow(
                    new RuntimeException(
                        'Fallo simulado al eliminar identidad'
                    )
                );

            // Crear un registro real de Firebase, pero con datos ficticios.
            $firebaseUser = UserRecord::fromResponseData([
                'localId' => $uid,
                'email' => $uid . '@example.test',
                'emailVerified' => true,
                'disabled' => false,
                'createdAt' => (string) (time() * 1000),
            ]);

            // Simular que Firebase confirma que la identidad existe.
            $auth->shouldReceive('getUser')
                ->once()
                ->with($uid)
                ->andReturn($firebaseUser);

            $response = $this->executeDeletion($uid, $auth);

            // La eliminacion fallo y debe responder con error.
            $this->assertSame(500, $response->getStatusCode());

            $snapshot = $userDocument->snapshot();

            // El perfil debe seguir existiendo.
            $this->assertTrue($snapshot->exists());

            // La identidad sigue existiendo y el perfil debe recuperarse.
            $this->assertSame(
                'active',
                $snapshot->data()['status'] ?? null
            );
        } finally {
            $userDocument->delete();
        }
    }

    public function test_does_not_delete_nonexistent_account(): void
    {
        $uid = $this->newTestUid();

        $auth = $this->mockAuthentication($uid);

        $auth->shouldNotReceive('deleteUser');

        $response = $this->executeDeletion($uid, $auth);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_does_not_delete_account_with_invalid_profile(): void
    {
        $uid = $this->newTestUid();

        $userDocument = $this->firestore
            ->collection('users')
            ->document($uid);

        try {
            $userDocument->set([
                'authSubject' => 'another-user',
                'name' => 'Usuario de prueba',
                'role' => 'customer',
                'status' => 'active',
            ]);

            $auth = $this->mockAuthentication($uid);

            $auth->shouldNotReceive('deleteUser');

            $response = $this->executeDeletion($uid, $auth);

            $this->assertSame(403, $response->getStatusCode());

            $this->assertTrue(
                $userDocument->snapshot()->exists()
            );
        } finally {
            $userDocument->delete();
        }
    }

    private function executeDeletion(
        string $uid,
        Auth $auth
    ): \Illuminate\Http\JsonResponse {
        $request = Request::create('/api/users/me', 'DELETE');

        $request->headers->set(
            'Authorization',
            'Bearer simulated-test-token'
        );

        $request->attributes->set('firebase_uid', $uid);

        $request->attributes->set('firebase_profile', [
            'role' => 'customer',
            'status' => 'active',
        ]);

        return (new DeleteAccountController())->destroy(
            $request,
            $auth,
            $this->firestore
        );
    }

    private function mockAuthentication(string $uid): Auth
    {
        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->andReturn(
                new DataSet([
                    'sub' => $uid,
                    'auth_time' => time(),
                ], '')
            );

        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('simulated-test-token', true)
            ->andReturn($token);

        return $auth;
    }

    private function userData(string $uid): array
    {
        return [
            'authSubject' => $uid,
            'name' => 'Usuario de prueba',
            'email' => $uid . '@example.test',
            'role' => 'customer',
            'status' => 'active',
        ];
    }

    private function newTestUid(): string
    {
        return 'delete-account-test-' . bin2hex(random_bytes(12));
    }
}
