<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DeleteAccountController;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeleteAccountRelatedRecordsTest extends TestCase
{
    private FirestoreClient $firestore;

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca ejecutar estas pruebas contra Firebase real.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->markTestSkipped(
                'Estas pruebas requieren Firestore Emulator de BluePass.'
            );
        }

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
        ]);
    }

    public static function relatedCollections(): array
    {
        return [
            'pedidos' => ['orders'],
            'boletos' => ['tickets'],
            'reservas' => ['reservations'],
            'notificaciones' => ['notifications'],
            'correos de compra' => ['purchaseEmailOutbox'],
        ];
    }

    #[DataProvider('relatedCollections')]
    public function test_blocks_deletion_when_related_records_exist(
        string $collectionName
    ): void {
        $uid = 'delete-account-test-' . bin2hex(random_bytes(10));

        $userDocument = $this->firestore
            ->collection('users')
            ->document($uid);

        $relatedDocument = $this->firestore
            ->collection($collectionName)
            ->document($uid);

        try {
            $userDocument->set($this->userData($uid));

            $relatedDocument->set([
                'userId' => $uid,
                'status' => 'test',
            ]);

            $response = $this->deleteRequest($uid);

            $this->assertSame(409, $response->getStatusCode());

            $data = json_decode(
                $response->getContent(),
                true
            );

            $this->assertSame(
                'ACCOUNT_HAS_RELATED_RECORDS',
                $data['code'] ?? null
            );

            $snapshot = $userDocument->snapshot();

            $this->assertTrue($snapshot->exists());
            $this->assertSame(
                'active',
                $snapshot->data()['status'] ?? null
            );
        } finally {
            $relatedDocument->delete();
            $userDocument->delete();
        }
    }

    public function test_blocks_deletion_when_favorites_exist(): void
    {
        $uid = 'delete-account-test-' . bin2hex(random_bytes(10));

        $userDocument = $this->firestore
            ->collection('users')
            ->document($uid);

        $favoriteDocument = $userDocument
            ->collection('favorites')
            ->document('test-event');

        try {
            $userDocument->set($this->userData($uid));

            $favoriteDocument->set([
                'eventId' => 'test-event',
            ]);

            $response = $this->deleteRequest($uid);

            $this->assertSame(409, $response->getStatusCode());

            $data = json_decode(
                $response->getContent(),
                true
            );

            $this->assertSame(
                'ACCOUNT_HAS_RELATED_RECORDS',
                $data['code'] ?? null
            );

            $this->assertTrue($userDocument->snapshot()->exists());
            $this->assertTrue($favoriteDocument->snapshot()->exists());
        } finally {
            $favoriteDocument->delete();
            $userDocument->delete();
        }
    }

    private function deleteRequest(string $uid): \Illuminate\Http\JsonResponse
    {
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

        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->andReturn(new DataSet([
                'sub' => $uid,
                'auth_time' => time(),
            ], ''));

        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('simulated-test-token', true)
            ->andReturn($token);

        // El controlador nunca debe eliminar la identidad
        // cuando detecta datos vinculados.
        $auth->shouldNotReceive('deleteUser');

        return (new DeleteAccountController())->destroy(
            $request,
            $auth,
            $this->firestore
        );
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
}
