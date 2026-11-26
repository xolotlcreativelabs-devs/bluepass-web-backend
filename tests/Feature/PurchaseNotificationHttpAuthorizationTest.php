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

class PurchaseNotificationHttpAuthorizationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $ownUserDocument;

    private DocumentReference $otherUserDocument;

    private DocumentReference $ownNotificationDocument;

    private DocumentReference $otherNotificationDocument;

    private string $ownUserId;

    private string $otherUserId;

    private string $ownNotificationId;

    private string $otherNotificationId;

    private const ENDPOINT = '/api/users/me/notifications';

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

        // El middleware y el controlador usan el mismo emulador.
        $this->app->instance(
            FirestoreClient::class,
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->ownUserId = 'cliente-notify-http-' . $suffix;
        $this->otherUserId = 'otro-notify-http-' . $suffix;

        $this->ownNotificationId = 'notify-own-' . $suffix;
        $this->otherNotificationId = 'notify-other-' . $suffix;

        $users = $this->firestore->collection('users');
        $notifications = $this->firestore->collection(
            'notifications'
        );

        $this->ownUserDocument = $users->document(
            $this->ownUserId
        );

        $this->otherUserDocument = $users->document(
            $this->otherUserId
        );

        $this->ownNotificationDocument = $notifications->document(
            $this->ownNotificationId
        );

        $this->otherNotificationDocument = $notifications->document(
            $this->otherNotificationId
        );

        $this->ownUserDocument->set(
            $this->userData($this->ownUserId)
        );

        $this->otherUserDocument->set(
            $this->userData($this->otherUserId)
        );

        $this->ownNotificationDocument->set(
            $this->notificationData($this->ownUserId)
        );

        $this->otherNotificationDocument->set(
            $this->notificationData($this->otherUserId)
        );
    }

    /**
     * Preparar un perfil de comprador.
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
     * Preparar una notificacion de prueba.
     */
    private function notificationData(string $userId): array
    {
        return [
            'userId' => $userId,
            'orderId' => 'pedido-notify-http',
            'type' => 'purchase_confirmed',
            'title' => 'Compra confirmada',
            'message' => 'Tus boletos estan disponibles.',
            'status' => 'unread',
            'channel' => 'in_app',
            'ticketCount' => 2,
            'createdAt' => new Timestamp(
                new DateTimeImmutable()
            ),
            'readAt' => null,
        ];
    }

    /**
     * Simular un token Firebase valido.
     */
    private function authenticate(
        string $uid,
        string $role = 'customer'
    ): void {
        $userDocument = $uid === $this->otherUserId
            ? $this->otherUserDocument
            : $this->ownUserDocument;

        $userDocument->update([
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

        $firebaseUser = UserRecord::fromResponseData([
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
            ->andReturn($firebaseUser);

        $this->app->instance(Auth::class, $auth);
    }

    /**
     * Las notificaciones requieren autenticacion.
     */
    public function test_notifications_require_authentication(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldNotReceive('verifyIdToken');

        $this->app->instance(Auth::class, $auth);

        $this->getJson(self::ENDPOINT)
            ->assertStatus(401)
            ->assertJsonMissingPath('notifications');
    }

    /**
     * Rechazar tokens Firebase invalidos.
     */
    public function test_notifications_reject_invalid_token(): void
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
            ->getJson(self::ENDPOINT)
            ->assertStatus(401)
            ->assertJsonMissingPath('notifications');
    }

    /**
     * Los vendedores no pueden consultar notificaciones.
     */
    public function test_seller_cannot_access_notifications(): void
    {
        $this->authenticate(
            $this->ownUserId,
            'seller'
        );

        $this->withToken('token-prueba')
            ->getJson(self::ENDPOINT)
            ->assertStatus(403)
            ->assertJsonMissingPath('notifications');
    }

    /**
     * Los administradores no usan esta ruta de compradores.
     */
    public function test_admin_cannot_access_notifications(): void
    {
        $this->authenticate(
            $this->ownUserId,
            'admin'
        );

        $this->withToken('token-prueba')
            ->getJson(self::ENDPOINT)
            ->assertStatus(403)
            ->assertJsonMissingPath('notifications');
    }

    /**
     * El comprador solo recibe sus notificaciones.
     */
    public function test_customer_lists_only_own_notifications(): void
    {
        $this->authenticate($this->ownUserId);

        $response = $this->withToken('token-prueba')
            ->getJson(self::ENDPOINT);

        $response->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('unreadCount', 1);

        $ids = array_column(
            $response->json('notifications'),
            'id'
        );

        $this->assertContains(
            $this->ownNotificationId,
            $ids
        );

        $this->assertNotContains(
            $this->otherNotificationId,
            $ids
        );
    }

    /**
     * El segundo comprador tampoco puede ver datos del primero.
     */
    public function test_other_customer_lists_only_own_notifications(): void
    {
        $this->authenticate($this->otherUserId);

        $response = $this->withToken('token-prueba')
            ->getJson(self::ENDPOINT);

        $response->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('unreadCount', 1);

        $ids = array_column(
            $response->json('notifications'),
            'id'
        );

        $this->assertContains(
            $this->otherNotificationId,
            $ids
        );

        $this->assertNotContains(
            $this->ownNotificationId,
            $ids
        );
    }

    /**
     * No exponer referencias internas ni datos privados.
     */
    public function test_notifications_hide_sensitive_fields(): void
    {
        $this->ownNotificationDocument->update([
            [
                'path' => 'paymentReference',
                'value' => 'private-payment-reference',
            ],
            [
                'path' => 'qrToken',
                'value' => 'private-qr-token',
            ],
            [
                'path' => 'qrTokenHash',
                'value' => 'private-qr-hash',
            ],
        ]);

        $this->authenticate($this->ownUserId);

        $response = $this->withToken('token-prueba')
            ->getJson(self::ENDPOINT);

        $response->assertStatus(200);

        $notifications = $response->json('notifications');

        $this->assertCount(1, $notifications);

        $notification = $notifications[0];

        $this->assertArrayNotHasKey(
            'userId',
            $notification
        );

        $this->assertArrayNotHasKey(
            'paymentReference',
            $notification
        );

        $this->assertArrayNotHasKey(
            'qrToken',
            $notification
        );

        $this->assertArrayNotHasKey(
            'qrTokenHash',
            $notification
        );
    }

    /**
     * No permitir consultar notificaciones de otro usuario.
     */
    public function test_notifications_reject_user_id_parameter(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->getJson(
                self::ENDPOINT . '?userId=' . $this->otherUserId
            )
            ->assertStatus(422)
            ->assertJsonMissingPath('notifications');
    }

    /**
     * Una cuenta sin notificaciones recibe una lista vacia.
     */
    public function test_customer_without_notifications_gets_empty_list(): void
    {
        $this->ownNotificationDocument->delete();

        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->getJson(self::ENDPOINT)
            ->assertStatus(200)
            ->assertJsonPath('notifications', [])
            ->assertJsonPath('count', 0)
            ->assertJsonPath('unreadCount', 0);
    }

    /**
     * Las respuestas privadas no deben guardarse en cache.
     */
    public function test_notifications_disable_cache(): void
    {
        $this->authenticate($this->ownUserId);

        $response = $this->withToken('token-prueba')
            ->getJson(self::ENDPOINT);

        $response->assertStatus(200);

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', '')
        );

        $this->assertSame(
            'nosniff',
            $response->headers->get('X-Content-Type-Options')
        );
    }

    /**
     * Eliminar los documentos temporales.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->ownNotificationDocument)) {
                $this->ownNotificationDocument->delete();
            }

            if (isset($this->otherNotificationDocument)) {
                $this->otherNotificationDocument->delete();
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
