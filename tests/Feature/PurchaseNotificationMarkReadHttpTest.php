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

class PurchaseNotificationMarkReadHttpTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        // Proteger la base de datos real.
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

        // Compartir el emulador con el middleware y controlador.
        $this->app->instance(
            FirestoreClient::class,
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->ownUserId = 'buyer-read-http-' . $suffix;
        $this->otherUserId = 'other-read-http-' . $suffix;

        $this->ownNotificationId = 'notify-read-http-' . $suffix;
        $this->otherNotificationId = 'notify-other-http-' . $suffix;

        $users = $this->firestore->collection('users');

        $notifications = $this->firestore
            ->collection('notifications');

        $this->ownUserDocument = $users
            ->document($this->ownUserId);

        $this->otherUserDocument = $users
            ->document($this->otherUserId);

        $this->ownNotificationDocument = $notifications
            ->document($this->ownNotificationId);

        $this->otherNotificationDocument = $notifications
            ->document($this->otherNotificationId);

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
     * Preparar el perfil de usuario.
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
     * Preparar una notificacion sin leer.
     */
    private function notificationData(string $userId): array
    {
        return [
            'userId' => $userId,
            'orderId' => 'order-read-http',
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
     * Obtener la ruta para marcar una notificacion.
     */
    private function endpoint(string $notificationId): string
    {
        return '/api/users/me/notifications/' .
            $notificationId . '/read';
    }

    /**
     * Simular la autenticacion de Firebase.
     */
    private function authenticate(
        string $uid,
        string $role = 'customer',
        int $requests = 1
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
            ->times($requests)
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
            ->times($requests)
            ->with('token-prueba', true)
            ->andReturn($token);

        $auth->shouldReceive('getUser')
            ->times($requests)
            ->with($uid)
            ->andReturn($firebaseUser);

        $this->app->instance(Auth::class, $auth);
    }

    /**
     * La ruta requiere autenticacion.
     */
    public function test_read_requires_authentication(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldNotReceive('verifyIdToken');

        $this->app->instance(Auth::class, $auth);

        $this->patchJson(
            $this->endpoint($this->ownNotificationId)
        )
            ->assertStatus(401)
            ->assertJsonMissingPath('notification');

        $this->assertSame(
            'unread',
            $this->ownNotificationDocument
                ->snapshot()
                ->data()['status']
        );
    }

    /**
     * No aceptar tokens invalidos.
     */
    public function test_read_rejects_invalid_token(): void
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
            ->patchJson(
                $this->endpoint($this->ownNotificationId)
            )
            ->assertStatus(401);
    }

    /**
     * Un vendedor no puede marcar notificaciones.
     */
    public function test_seller_cannot_mark_notification(): void
    {
        $this->authenticate(
            $this->ownUserId,
            'seller'
        );

        $this->withToken('token-prueba')
            ->patchJson(
                $this->endpoint($this->ownNotificationId)
            )
            ->assertStatus(403);

        $this->assertSame(
            'unread',
            $this->ownNotificationDocument
                ->snapshot()
                ->data()['status']
        );
    }

    /**
     * Un administrador no utiliza la ruta de compradores.
     */
    public function test_admin_cannot_mark_notification(): void
    {
        $this->authenticate(
            $this->ownUserId,
            'admin'
        );

        $this->withToken('token-prueba')
            ->patchJson(
                $this->endpoint($this->ownNotificationId)
            )
            ->assertStatus(403);
    }

    /**
     * Un comprador puede marcar su notificacion como leida.
     */
    public function test_customer_can_mark_own_notification(): void
    {
        $this->authenticate($this->ownUserId);

        $response = $this->withToken('token-prueba')
            ->patchJson(
                $this->endpoint($this->ownNotificationId)
            );

        $response->assertStatus(200)
            ->assertJsonPath(
                'notification.id',
                $this->ownNotificationId
            )
            ->assertJsonPath(
                'notification.status',
                'read'
            )
            ->assertJsonPath(
                'alreadyRead',
                false
            );

        $this->assertNotNull(
            $response->json('notification.readAt')
        );

        $saved = $this->ownNotificationDocument
            ->snapshot()
            ->data();

        $this->assertSame('read', $saved['status']);

        $this->assertInstanceOf(
            Timestamp::class,
            $saved['readAt']
        );
    }

    /**
     * Una segunda solicitud no cambia la fecha original.
     */
    public function test_repeated_read_preserves_timestamp(): void
    {
        $this->authenticate(
            $this->ownUserId,
            'customer',
            2
        );

        $first = $this->withToken('token-prueba')
            ->patchJson(
                $this->endpoint($this->ownNotificationId)
            );

        $first->assertStatus(200)
            ->assertJsonPath('alreadyRead', false);

        $original = $this->ownNotificationDocument
            ->snapshot()
            ->data()['readAt'];

        $second = $this->withToken('token-prueba')
            ->patchJson(
                $this->endpoint($this->ownNotificationId)
            );

        $second->assertStatus(200)
            ->assertJsonPath('alreadyRead', true)
            ->assertJsonPath(
                'notification.status',
                'read'
            );

        $saved = $this->ownNotificationDocument
            ->snapshot()
            ->data()['readAt'];

        $this->assertSame(
            $original->get()->format('U.u'),
            $saved->get()->format('U.u')
        );
    }

    /**
     * No permitir modificar notificaciones de otros compradores.
     */
    public function test_customer_cannot_mark_another_notification(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->patchJson(
                $this->endpoint($this->otherNotificationId)
            )
            ->assertStatus(404)
            ->assertJsonMissingPath('notification');

        $saved = $this->otherNotificationDocument
            ->snapshot()
            ->data();

        $this->assertSame('unread', $saved['status']);
        $this->assertNull($saved['readAt']);
    }

    /**
     * Una notificacion inexistente devuelve 404.
     */
    public function test_missing_notification_returns_404(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->patchJson(
                $this->endpoint(
                    'notification-missing-' .
                    bin2hex(random_bytes(8))
                )
            )
            ->assertStatus(404);
    }

    /**
     * No permitir modificar campos privados.
     */
    public function test_read_rejects_protected_fields(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->patchJson(
                $this->endpoint($this->ownNotificationId),
                [
                    'userId' => $this->otherUserId,
                    'status' => 'read',
                ]
            )
            ->assertStatus(422);

        $this->assertSame(
            'unread',
            $this->ownNotificationDocument
                ->snapshot()
                ->data()['status']
        );
    }

    /**
     * No aceptar identificadores de usuario en la consulta.
     */
    public function test_read_rejects_query_parameters(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->patchJson(
                $this->endpoint($this->ownNotificationId) .
                    '?userId=' . $this->otherUserId
            )
            ->assertStatus(422);

        $this->assertSame(
            'unread',
            $this->ownNotificationDocument
                ->snapshot()
                ->data()['status']
        );
    }

    /**
     * No almacenar la respuesta privada en cache.
     */
    public function test_read_disables_cache(): void
    {
        $this->authenticate($this->ownUserId);

        $response = $this->withToken('token-prueba')
            ->patchJson(
                $this->endpoint($this->ownNotificationId)
            );

        $response->assertStatus(200);

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get(
                'Cache-Control',
                ''
            )
        );

        $this->assertSame(
            'nosniff',
            $response->headers->get(
                'X-Content-Type-Options'
            )
        );
    }

    /**
     * Eliminar los documentos de prueba.
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
