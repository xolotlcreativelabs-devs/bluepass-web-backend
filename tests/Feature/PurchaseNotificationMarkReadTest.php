<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PurchaseNotificationController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class PurchaseNotificationMarkReadTest extends TestCase
{
    private FirestoreClient $firestore;

    private PurchaseNotificationController $controller;

    private DocumentReference $ownNotification;

    private DocumentReference $otherNotification;

    private string $ownUserId;

    private string $otherUserId;

    private string $ownNotificationId;

    private string $otherNotificationId;

    protected function setUp(): void
    {
        parent::setUp();

        // Usar exclusivamente Firestore Emulator.
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

        $this->ownUserId = 'buyer-read-' . $suffix;
        $this->otherUserId = 'other-read-' . $suffix;

        $this->ownNotificationId = 'notification-read-' . $suffix;
        $this->otherNotificationId = 'notification-other-' . $suffix;

        $collection = $this->firestore->collection(
            'notifications'
        );

        $this->ownNotification = $collection->document(
            $this->ownNotificationId
        );

        $this->otherNotification = $collection->document(
            $this->otherNotificationId
        );

        $this->ownNotification->set(
            $this->notificationData($this->ownUserId)
        );

        $this->otherNotification->set(
            $this->notificationData($this->otherUserId)
        );
    }

    /**
     * Preparar una notificacion de prueba.
     */
    private function notificationData(string $userId): array
    {
        return [
            'userId' => $userId,
            'orderId' => 'order-read-test',
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
     * Ejecutar el controlador con una identidad simulada.
     */
    private function markRead(
        string $notificationId,
        mixed $uid = null,
        mixed $role = 'customer',
        array $body = [],
        array $query = []
    ): array {
        $request = Request::create(
            '/api/users/me/notifications/' .
                $notificationId . '/read',
            'PATCH',
            $query,
            [],
            [],
            [],
            json_encode($body, JSON_THROW_ON_ERROR)
        );

        $request->headers->set(
            'Content-Type',
            'application/json'
        );

        $request->attributes->set(
            'firebase_uid',
            $uid ?? $this->ownUserId
        );

        $request->attributes->set(
            'firebase_role',
            $role
        );

        $response = $this->controller->markRead(
            $request,
            $this->firestore,
            $notificationId
        );

        return [
            'status' => $response->getStatusCode(),
            'data' => $response->getData(true),
            'headers' => $response->headers,
        ];
    }

    public function test_customer_can_mark_own_notification_as_read(): void
    {
        $result = $this->markRead(
            $this->ownNotificationId
        );

        $this->assertSame(200, $result['status']);

        $this->assertSame(
            $this->ownNotificationId,
            $result['data']['notification']['id']
        );

        $this->assertSame(
            'read',
            $result['data']['notification']['status']
        );

        $this->assertFalse(
            $result['data']['alreadyRead']
        );

        $this->assertNotNull(
            $result['data']['notification']['readAt']
        );

        $saved = $this->ownNotification->snapshot()->data();

        $this->assertSame('read', $saved['status']);

        $this->assertInstanceOf(
            Timestamp::class,
            $saved['readAt']
        );
    }

    public function test_second_request_preserves_original_read_date(): void
    {
        $first = $this->markRead(
            $this->ownNotificationId
        );

        $this->assertSame(200, $first['status']);

        $original = $this->ownNotification
            ->snapshot()
            ->data()['readAt'];

        $second = $this->markRead(
            $this->ownNotificationId
        );

        $this->assertSame(200, $second['status']);

        $this->assertTrue(
            $second['data']['alreadyRead']
        );

        $saved = $this->ownNotification
            ->snapshot()
            ->data()['readAt'];

        $this->assertSame(
            $original->get()->format('U.u'),
            $saved->get()->format('U.u')
        );
    }

    public function test_customer_cannot_mark_other_buyers_notification(): void
    {
        $result = $this->markRead(
            $this->otherNotificationId
        );

        $this->assertSame(404, $result['status']);

        $saved = $this->otherNotification
            ->snapshot()
            ->data();

        $this->assertSame('unread', $saved['status']);

        $this->assertNull($saved['readAt']);
    }

    public function test_missing_notification_returns_404(): void
    {
        $result = $this->markRead(
            'notification-missing-' . bin2hex(random_bytes(8))
        );

        $this->assertSame(404, $result['status']);
    }

    public function test_invalid_notification_id_is_rejected(): void
    {
        $result = $this->markRead(
            'invalid-id!'
        );

        $this->assertSame(404, $result['status']);
    }

    public function test_missing_identity_is_rejected(): void
    {
        $request = Request::create(
            '/api/users/me/notifications/' .
                $this->ownNotificationId . '/read',
            'PATCH'
        );

        $response = $this->controller->markRead(
            $request,
            $this->firestore,
            $this->ownNotificationId
        );

        $this->assertSame(
            401,
            $response->getStatusCode()
        );

        $this->assertSame(
            'unread',
            $this->ownNotification->snapshot()->data()['status']
        );
    }

    public function test_seller_cannot_mark_notifications(): void
    {
        $result = $this->markRead(
            $this->ownNotificationId,
            $this->ownUserId,
            'seller'
        );

        $this->assertSame(403, $result['status']);

        $this->assertSame(
            'unread',
            $this->ownNotification->snapshot()->data()['status']
        );
    }

    public function test_admin_cannot_mark_notifications(): void
    {
        $result = $this->markRead(
            $this->ownNotificationId,
            $this->ownUserId,
            'admin'
        );

        $this->assertSame(403, $result['status']);
    }

    public function test_request_with_protected_fields_is_rejected(): void
    {
        $result = $this->markRead(
            $this->ownNotificationId,
            $this->ownUserId,
            'customer',
            [
                'userId' => $this->otherUserId,
                'status' => 'read',
            ]
        );

        $this->assertSame(422, $result['status']);

        $this->assertSame(
            'unread',
            $this->ownNotification->snapshot()->data()['status']
        );
    }

    public function test_read_operation_updates_unread_counter(): void
    {
        $beforeRequest = Request::create(
            '/api/users/me/notifications',
            'GET'
        );

        $beforeRequest->attributes->set(
            'firebase_uid',
            $this->ownUserId
        );

        $beforeRequest->attributes->set(
            'firebase_role',
            'customer'
        );

        $before = $this->controller->index(
            $beforeRequest,
            $this->firestore
        )->getData(true);

        $this->assertSame(1, $before['unreadCount']);

        $result = $this->markRead(
            $this->ownNotificationId
        );

        $this->assertSame(200, $result['status']);

        $after = $this->controller->index(
            $beforeRequest,
            $this->firestore
        )->getData(true);

        $this->assertSame(0, $after['unreadCount']);
        $this->assertSame(1, $after['count']);
    }

    /**
     * Eliminar los documentos temporales.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->ownNotification)) {
                $this->ownNotification->delete();
            }

            if (isset($this->otherNotification)) {
                $this->otherNotification->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
