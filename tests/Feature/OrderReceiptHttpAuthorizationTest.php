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

class OrderReceiptHttpAuthorizationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $ownUserDocument;

    private DocumentReference $otherUserDocument;

    private DocumentReference $ownOrderDocument;

    private DocumentReference $otherOrderDocument;

    private DocumentReference $pendingOrderDocument;

    private string $ownUserId;

    private string $otherUserId;

    private string $ownOrderId;

    private string $otherOrderId;

    private string $pendingOrderId;

    protected function setUp(): void
    {
        parent::setUp();

        // Proteger los datos reales.
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

        // Compartir Firestore entre middleware y controlador.
        $this->app->instance(
            FirestoreClient::class,
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->ownUserId = 'cliente-recibo-http-' . $suffix;
        $this->otherUserId = 'otro-recibo-http-' . $suffix;

        $this->ownOrderId = 'pedido-recibo-http-' . $suffix;
        $this->otherOrderId = 'pedido-ajeno-recibo-' . $suffix;
        $this->pendingOrderId = 'pedido-pendiente-recibo-' . $suffix;

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

        $this->pendingOrderDocument = $orders
            ->document($this->pendingOrderId);

        $this->ownUserDocument->set(
            $this->userData($this->ownUserId)
        );

        $this->otherUserDocument->set(
            $this->userData($this->otherUserId)
        );

        $this->ownOrderDocument->set(
            $this->orderData($this->ownUserId)
        );

        $this->otherOrderDocument->set(
            $this->orderData($this->otherUserId)
        );

        $pending = $this->orderData($this->ownUserId);
        $pending['status'] = 'pending';

        unset($pending['paidAt']);

        $this->pendingOrderDocument->set($pending);
    }

    /**
     * Preparar un usuario de prueba.
     */
    private function userData(
        string $uid,
        string $role = 'customer'
    ): array {
        return [
            'name' => 'Comprador de prueba',
            'email' => 'cliente@ejemplo.com',
            'role' => $role,
            'status' => 'active',
            'authSubject' => $uid,
        ];
    }

    /**
     * Preparar un pedido con importes calculados.
     */
    private function orderData(string $uid): array
    {
        $timestamp = new Timestamp(new DateTimeImmutable());

        return [
            'userId' => $uid,
            'eventId' => 'evento-recibo-http',
            'sellerId' => 'vendedor-recibo-http',
            'channel' => 'online',
            'ticketTypeId' => 'general',
            'reservationId' => 'reserva-recibo-http',
            'items' => [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 2,
                    'unitPrice' => 350,
                    'subtotal' => 700,
                ],
            ],
            'quantity' => 2,
            'unitPrice' => 350,
            'currency' => 'MXN',
            'subtotal' => 700,
            'fees' => 0,
            'total' => 700,
            'status' => 'paid',
            'buyer' => [
                'id' => $uid,
                'name' => 'Comprador BluePass',
                'email' => 'comprador@example.com',
            ],
            'idempotencyKey' => 'clave-interna-prueba',
            'paymentReference' => 'referencia-interna-prueba',
            'createdAt' => $timestamp,
            'updatedAt' => $timestamp,
            'paidAt' => $timestamp,
        ];
    }

    /**
     * Simular la autenticacion de Firebase.
     */
    private function authenticate(
        string $uid,
        string $role = 'customer'
    ): void {
        $document = $uid === $this->otherUserId
            ? $this->otherUserDocument
            : $this->ownUserDocument;

        $document->update([
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

    /**
     * Preparar un servicio de autenticacion sin token.
     */
    private function unauthenticated(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldNotReceive('verifyIdToken');

        $this->app->instance(Auth::class, $auth);
    }

    /**
     * Construir la ruta del comprobante.
     */
    private function receiptUrl(string $orderId): string
    {
        return '/api/orders/' . $orderId . '/receipt';
    }

    public function test_receipt_requires_authentication(): void
    {
        $this->unauthenticated();

        $this->getJson(
            $this->receiptUrl($this->ownOrderId)
        )->assertStatus(401);
    }

    public function test_receipt_rejects_invalid_token(): void
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
            ->getJson($this->receiptUrl($this->ownOrderId))
            ->assertStatus(401);
    }

    public function test_seller_cannot_view_receipt(): void
    {
        $this->authenticate(
            $this->ownUserId,
            'seller'
        );

        $this->withToken('token-prueba')
            ->getJson($this->receiptUrl($this->ownOrderId))
            ->assertStatus(403);
    }

    public function test_admin_cannot_use_customer_receipt_route(): void
    {
        $this->authenticate(
            $this->ownUserId,
            'admin'
        );

        $this->withToken('token-prueba')
            ->getJson($this->receiptUrl($this->ownOrderId))
            ->assertStatus(403);
    }

    public function test_customer_can_view_own_paid_receipt(): void
    {
        $this->authenticate($this->ownUserId);

        $response = $this->withToken('token-prueba')
            ->getJson($this->receiptUrl($this->ownOrderId));

        $response->assertStatus(200)
            ->assertJsonPath(
                'receipt.orderId',
                $this->ownOrderId
            )
            ->assertJsonPath(
                'receipt.status',
                'paid'
            )
            ->assertJsonPath(
                'receipt.currency',
                'MXN'
            )
            ->assertJsonPath(
                'receipt.total',
                700
            );

        $this->assertNotEmpty(
            $response->json('receipt.paidAt')
        );
    }

    public function test_receipt_preserves_order_amounts(): void
    {
        $this->authenticate($this->ownUserId);

        $response = $this->withToken('token-prueba')
            ->getJson($this->receiptUrl($this->ownOrderId));

        $response->assertStatus(200)
            ->assertJsonPath('receipt.quantity', 2)
            ->assertJsonPath('receipt.subtotal', 700)
            ->assertJsonPath('receipt.fees', 0)
            ->assertJsonPath('receipt.total', 700)
            ->assertJsonPath(
                'receipt.items.0.unitPrice',
                350
            );
    }

    public function test_customer_cannot_view_another_receipt(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->getJson($this->receiptUrl($this->otherOrderId))
            ->assertStatus(404)
            ->assertJsonMissingPath('receipt');
    }

    public function test_other_customer_cannot_view_first_receipt(): void
    {
        $this->authenticate($this->otherUserId);

        $this->withToken('token-prueba')
            ->getJson($this->receiptUrl($this->ownOrderId))
            ->assertStatus(404)
            ->assertJsonMissingPath('receipt');
    }

    public function test_pending_order_has_no_receipt(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->getJson($this->receiptUrl($this->pendingOrderId))
            ->assertStatus(409)
            ->assertJsonMissingPath('receipt');
    }

    public function test_receipt_rejects_modified_amount_parameter(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->getJson(
                $this->receiptUrl($this->ownOrderId)
                    . '?total=1'
            )
            ->assertStatus(422)
            ->assertJsonMissingPath('receipt');
    }

    public function test_receipt_hides_internal_fields(): void
    {
        $this->authenticate($this->ownUserId);

        $response = $this->withToken('token-prueba')
            ->getJson($this->receiptUrl($this->ownOrderId));

        $response->assertStatus(200);

        $receipt = $response->json('receipt');

        foreach (
            [
                'idempotencyKey',
                'paymentReference',
                'reservationId',
                'qrValue',
                'qrTokenHash',
                'qrTokenEncrypted',
                'userId',
            ] as $field
        ) {
            $this->assertArrayNotHasKey(
                $field,
                $receipt
            );
        }
    }

    public function test_receipt_disables_cache(): void
    {
        $this->authenticate($this->ownUserId);

        $response = $this->withToken('token-prueba')
            ->getJson($this->receiptUrl($this->ownOrderId));

        $response->assertStatus(200);

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', '')
        );

        $this->assertSame(
            'nosniff',
            $response->headers->get(
                'X-Content-Type-Options'
            )
        );
    }

    /**
     * Eliminar los documentos temporales.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->ownOrderDocument)) {
                $this->ownOrderDocument->delete();
            }

            if (isset($this->otherOrderDocument)) {
                $this->otherOrderDocument->delete();
            }

            if (isset($this->pendingOrderDocument)) {
                $this->pendingOrderDocument->delete();
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
}
