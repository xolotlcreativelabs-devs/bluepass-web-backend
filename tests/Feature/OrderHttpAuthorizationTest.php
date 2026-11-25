<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class OrderHttpAuthorizationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $ownUserDocument;

    private DocumentReference $otherUserDocument;

    private DocumentReference $ownOrderDocument;

    private DocumentReference $otherOrderDocument;

    private string $ownUserId;

    private string $otherUserId;

    private string $ownOrderId;

    private string $otherOrderId;

    protected function setUp(): void
    {
        parent::setUp();

        // No permitir conexiones a la base de datos real.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'Estas pruebas requieren Firestore Emulator.'
            );
        }

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        // Middleware y controlador utilizaran el mismo emulador.
        $this->app->instance(
            FirestoreClient::class,
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->ownUserId = 'cliente-http-' . $suffix;
        $this->otherUserId = 'otro-http-' . $suffix;

        $this->ownOrderId = 'pedido-http-' . $suffix;
        $this->otherOrderId = 'pedido-ajeno-' . $suffix;

        $users = $this->firestore->collection('users');
        $orders = $this->firestore->collection('orders');

        $this->ownUserDocument = $users
            ->document($this->ownUserId);

        $this->otherUserDocument = $users
            ->document($this->otherUserId);

        $this->ownOrderDocument = $orders
            ->document($this->ownOrderId);

        $this->otherOrderDocument = $orders
            ->document($this->otherOrderId);

        $this->ownUserDocument->set(
            $this->userData($this->ownUserId, 'customer')
        );

        $this->otherUserDocument->set(
            $this->userData($this->otherUserId, 'customer')
        );

        $this->ownOrderDocument->set(
            $this->orderData($this->ownUserId)
        );

        $this->otherOrderDocument->set(
            $this->orderData($this->otherUserId)
        );
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->ownOrderDocument)) {
                $this->ownOrderDocument->delete();
            }

            if (isset($this->otherOrderDocument)) {
                $this->otherOrderDocument->delete();
            }

            if (isset($this->ownUserDocument)) {
                $this->ownUserDocument->delete();
            }

            if (isset($this->otherUserDocument)) {
                $this->otherUserDocument->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function userData(
        string $uid,
        string $role
    ): array {
        return [
            'name' => 'Comprador de prueba',
            'email' => 'cliente@ejemplo.com',
            'role' => $role,
            'status' => 'active',
            'authSubject' => $uid,
        ];
    }

    private function orderData(string $userId): array
    {
        $now = new DateTimeImmutable();

        return [
            'userId' => $userId,
            'eventId' => 'evento-http',
            'reservationId' => 'reserva-http',
            'ticketTypeId' => 'general',
            'quantity' => 2,
            'unitPrice' => 350,
            'currency' => 'MXN',
            'subtotal' => 700,
            'fees' => 0,
            'total' => 700,
            'status' => 'pending',
            'buyer' => [
                'id' => $userId,
                'name' => 'Comprador de prueba',
                'email' => 'cliente@ejemplo.com',
            ],
            'expiresAt' => new Timestamp(
                $now->modify('+10 minutes')
            ),
            'createdAt' => new Timestamp($now),
            'updatedAt' => new Timestamp($now),
        ];
    }

    /**
     * Simular un token Firebase valido.
     */
    private function authenticate(
        string $uid,
        string $role = 'customer'
    ): void {
        // Cambiar el rol almacenado en Firestore si es necesario.
        $this->ownUserDocument->update([
            [
                'path' => 'role',
                'value' => $role,
            ],
        ]);

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
            ->with('token-prueba', true)
            ->andReturn($token);

        $auth->shouldReceive('getUser')
            ->once()
            ->with($uid)
            ->andReturn($user);

        $this->app->instance(Auth::class, $auth);
    }

    public function test_orders_require_authentication(): void
    {
        $auth = Mockery::mock(Auth::class);
        $auth->shouldNotReceive('verifyIdToken');

        $this->app->instance(Auth::class, $auth);

        $this->getJson('/api/orders')
            ->assertStatus(401);
    }

    public function test_orders_reject_invalid_token(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-invalido', true)
            ->andThrow(
                new FailedToVerifyToken('Token invalido.')
            );

        $auth->shouldNotReceive('getUser');

        $this->app->instance(Auth::class, $auth);

        $this->withToken('token-invalido')
            ->getJson('/api/orders')
            ->assertStatus(401);
    }

    public function test_seller_cannot_access_orders(): void
    {
        $this->authenticate(
            $this->ownUserId,
            'seller'
        );

        $this->withToken('token-prueba')
            ->getJson('/api/orders')
            ->assertStatus(403);
    }

    public function test_customer_can_list_own_orders(): void
    {
        $this->authenticate($this->ownUserId);

        $response = $this->withToken('token-prueba')
            ->getJson('/api/orders');

        $response->assertStatus(200);

        $ids = array_column(
            $response->json('orders'),
            'id'
        );

        $this->assertContains($this->ownOrderId, $ids);
        $this->assertNotContains($this->otherOrderId, $ids);
    }

    public function test_customer_can_view_own_order(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->getJson('/api/orders/' . $this->ownOrderId)
            ->assertStatus(200)
            ->assertJsonPath(
                'order.id',
                $this->ownOrderId
            )
            ->assertJsonPath(
                'order.total',
                700
            );
    }

    public function test_customer_cannot_view_another_order(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->getJson('/api/orders/' . $this->otherOrderId)
            ->assertStatus(404)
            ->assertJsonMissingPath('order');
    }
}
