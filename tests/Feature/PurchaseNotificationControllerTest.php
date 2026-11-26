<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PurchaseNotificationController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class PurchaseNotificationControllerTest extends TestCase
{
    private FirestoreClient $firestore;

    private PurchaseNotificationController $controller;

    private string $buyerId;

    private string $otherBuyerId;

    private array $notificationIds = [];

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

        $this->controller = new PurchaseNotificationController();

        $suffix = bin2hex(random_bytes(8));

        $this->buyerId = 'buyer-notification-' . $suffix;
        $this->otherBuyerId = 'other-notification-' . $suffix;
    }

    /**
     * Crear una notificacion temporal.
     */
    private function createNotification(
        string $userId,
        string $status = 'unread',
        string $channel = 'in_app',
        string $type = 'purchase_confirmed'
    ): string {
        $id = 'notification-' . bin2hex(random_bytes(12));

        $this->firestore
            ->collection('notifications')
            ->document($id)
            ->set([
                'userId' => $userId,
                'orderId' => 'order-' . $id,
                'type' => $type,
                'title' => 'Compra confirmada',
                'message' => 'Tus boletos estan disponibles.',
                'status' => $status,
                'channel' => $channel,
                'ticketCount' => 2,
                'createdAt' => new Timestamp(
                    new DateTimeImmutable()
                ),
                'readAt' => null,
            ]);

        $this->notificationIds[] = $id;

        return $id;
    }

    /**
     * Consultar el controlador con una identidad de prueba.
     */
    private function requestNotifications(
        mixed $uid,
        mixed $role,
        array $query = []
    ): array {
        $request = Request::create(
            '/api/users/me/notifications',
            'GET',
            $query
        );

        $request->attributes->set(
            'firebase_uid',
            $uid
        );

        $request->attributes->set(
            'firebase_role',
            $role
        );

        $response = $this->controller->index(
            $request,
            $this->firestore
        );

        return [
            'status' => $response->getStatusCode(),
            'data' => $response->getData(true),
            'headers' => $response->headers,
        ];
    }

    public function test_customer_can_view_own_notification(): void
    {
        $id = $this->createNotification(
            $this->buyerId
        );

        $result = $this->requestNotifications(
            $this->buyerId,
            'customer'
        );

        $this->assertSame(200, $result['status']);

        $data = $result['data'];

        $this->assertSame(1, $data['count']);
        $this->assertSame(1, $data['unreadCount']);

        $notification = $data['notifications'][0];

        $this->assertSame($id, $notification['id']);

        $this->assertSame(
            'purchase_confirmed',
            $notification['type']
        );

        $this->assertSame(
            'unread',
            $notification['status']
        );

        $this->assertSame(
            2,
            $notification['ticketCount']
        );

        $this->assertNotNull(
            $notification['createdAt']
        );
    }

    public function test_customer_cannot_view_other_buyers_notifications(): void
    {
        $ownId = $this->createNotification(
            $this->buyerId
        );

        $otherId = $this->createNotification(
            $this->otherBuyerId
        );

        $result = $this->requestNotifications(
            $this->buyerId,
            'customer'
        );

        $this->assertSame(200, $result['status']);

        $data = $result['data'];

        $this->assertSame(1, $data['count']);

        $ids = array_column(
            $data['notifications'],
            'id'
        );

        $this->assertContains($ownId, $ids);
        $this->assertNotContains($otherId, $ids);
    }

    public function test_missing_identity_is_rejected(): void
    {
        $result = $this->requestNotifications(
            null,
            null
        );

        $this->assertSame(401, $result['status']);

        $this->assertArrayNotHasKey(
            'notifications',
            $result['data']
        );
    }

    public function test_seller_cannot_view_notifications(): void
    {
        $this->createNotification(
            $this->buyerId
        );

        $result = $this->requestNotifications(
            $this->buyerId,
            'seller'
        );

        $this->assertSame(403, $result['status']);

        $this->assertArrayNotHasKey(
            'notifications',
            $result['data']
        );
    }

    public function test_admin_cannot_view_customer_notifications(): void
    {
        $result = $this->requestNotifications(
            $this->buyerId,
            'admin'
        );

        $this->assertSame(403, $result['status']);
    }

    public function test_empty_notifications_returns_empty_list(): void
    {
        $result = $this->requestNotifications(
            $this->buyerId,
            'customer'
        );

        $this->assertSame(200, $result['status']);

        $data = $result['data'];

        $this->assertSame([], $data['notifications']);
        $this->assertSame(0, $data['count']);
        $this->assertSame(0, $data['unreadCount']);
    }

    public function test_read_and_unread_counts_are_correct(): void
    {
        $this->createNotification(
            $this->buyerId,
            'unread'
        );

        $this->createNotification(
            $this->buyerId,
            'read'
        );

        $result = $this->requestNotifications(
            $this->buyerId,
            'customer'
        );

        $this->assertSame(200, $result['status']);

        $this->assertSame(
            2,
            $result['data']['count']
        );

        $this->assertSame(
            1,
            $result['data']['unreadCount']
        );
    }

    public function test_invalid_query_parameters_are_rejected(): void
    {
        $result = $this->requestNotifications(
            $this->buyerId,
            'customer',
            ['userId' => $this->otherBuyerId]
        );

        $this->assertSame(422, $result['status']);

        $this->assertArrayNotHasKey(
            'notifications',
            $result['data']
        );
    }

    public function test_internal_fields_are_not_exposed(): void
    {
        $id = $this->createNotification(
            $this->buyerId
        );

        $this->firestore
            ->collection('notifications')
            ->document($id)
            ->update([
                [
                    'path' => 'paymentReference',
                    'value' => 'private-payment-reference',
                ],
                [
                    'path' => 'qrToken',
                    'value' => 'private-qr-token',
                ],
            ]);

        $result = $this->requestNotifications(
            $this->buyerId,
            'customer'
        );

        $this->assertSame(200, $result['status']);

        $notification = $result['data']['notifications'][0];

        $this->assertArrayNotHasKey(
            'paymentReference',
            $notification
        );

        $this->assertArrayNotHasKey(
            'qrToken',
            $notification
        );

        $this->assertArrayNotHasKey(
            'userId',
            $notification
        );

        $this->assertArrayNotHasKey(
            'qrTokenHash',
            $notification
        );
    }

    public function test_private_responses_disable_cache(): void
    {
        $result = $this->requestNotifications(
            $this->buyerId,
            'customer'
        );

        $this->assertSame(200, $result['status']);

        $cacheControl = $result['headers']->get(
            'Cache-Control'
        );

        $this->assertStringContainsString(
            'no-store',
            $cacheControl
        );

        $this->assertSame(
            'nosniff',
            $result['headers']->get('X-Content-Type-Options')
        );
    }

    /**
     * Eliminar las notificaciones creadas por las pruebas.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                foreach ($this->notificationIds as $id) {
                    $this->firestore
                        ->collection('notifications')
                        ->document($id)
                        ->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
