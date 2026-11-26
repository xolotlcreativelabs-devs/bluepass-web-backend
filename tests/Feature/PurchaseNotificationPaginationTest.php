<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PurchaseNotificationController;
use DateTimeImmutable;
use DateTimeZone;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class PurchaseNotificationPaginationTest extends TestCase
{
    private FirestoreClient $firestore;

    private PurchaseNotificationController $controller;

    private string $buyerId;

    private string $otherBuyerId;

    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // No utilizar la base de datos real.
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

        $this->buyerId = 'buyer-page-' . $suffix;
        $this->otherBuyerId = 'other-page-' . $suffix;
    }

    /**
     * Crear notificaciones con fechas controladas.
     */
    private function createNotifications(
        int $quantity,
        ?string $userId = null,
        int $readCount = 0,
        bool $sameDate = false
    ): array {
        $userId ??= $this->buyerId;

        $ids = [];

        $baseDate = new DateTimeImmutable(
            '2026-11-25T12:00:00+00:00'
        );

        for ($position = 0; $position < $quantity; $position++) {
            $id = 'notify-page-' .
                bin2hex(random_bytes(8));

            $date = $sameDate
                ? $baseDate
                : $baseDate->modify(
                    '+' . $position . ' seconds'
                );

            $isRead = $position < $readCount;

            $this->firestore
                ->collection('notifications')
                ->document($id)
                ->set([
                    'userId' => $userId,
                    'orderId' => 'order-' . $id,
                    'type' => 'purchase_confirmed',
                    'title' => 'Compra confirmada',
                    'message' => 'Tus boletos estan disponibles.',
                    'status' => $isRead ? 'read' : 'unread',
                    'channel' => 'in_app',
                    'ticketCount' => 1,
                    'createdAt' => new Timestamp($date),
                    'readAt' => $isRead
                        ? new Timestamp($date)
                        : null,
                ]);

            $this->createdIds[] = $id;
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Consultar una pagina con identidad de comprador.
     */
    private function getPage(
        ?string $cursor = null,
        ?string $userId = null
    ): array {
        $query = [];

        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }

        $request = Request::create(
            '/api/users/me/notifications',
            'GET',
            $query
        );

        $request->attributes->set(
            'firebase_uid',
            $userId ?? $this->buyerId
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        $response = $this->controller->index(
            $request,
            $this->firestore
        );

        return [
            'status' => $response->getStatusCode(),
            'data' => $response->getData(true),
        ];
    }

    public function test_first_page_contains_twenty_notifications(): void
    {
        $this->createNotifications(25);

        $result = $this->getPage();

        $this->assertSame(200, $result['status']);

        $data = $result['data'];

        $this->assertSame(20, $data['count']);
        $this->assertCount(20, $data['notifications']);
        $this->assertSame(25, $data['unreadCount']);
        $this->assertTrue($data['hasMore']);
        $this->assertIsString($data['nextCursor']);
        $this->assertNotSame('', $data['nextCursor']);
    }

    public function test_next_page_has_remaining_notifications_without_duplicates(): void
    {
        $createdIds = $this->createNotifications(45);

        $first = $this->getPage();

        $this->assertSame(200, $first['status']);
        $this->assertSame(20, $first['data']['count']);

        $second = $this->getPage(
            $first['data']['nextCursor']
        );

        $this->assertSame(200, $second['status']);
        $this->assertSame(20, $second['data']['count']);

        $third = $this->getPage(
            $second['data']['nextCursor']
        );

        $this->assertSame(200, $third['status']);
        $this->assertSame(5, $third['data']['count']);
        $this->assertFalse($third['data']['hasMore']);
        $this->assertNull($third['data']['nextCursor']);

        $allIds = array_merge(
            array_column(
                $first['data']['notifications'],
                'id'
            ),
            array_column(
                $second['data']['notifications'],
                'id'
            ),
            array_column(
                $third['data']['notifications'],
                'id'
            )
        );

        $this->assertCount(45, $allIds);

        $this->assertCount(
            45,
            array_unique($allIds)
        );

        $this->assertEqualsCanonicalizing(
            $createdIds,
            $allIds
        );
    }

    public function test_unread_count_covers_all_pages(): void
    {
        $this->createNotifications(
            25,
            $this->buyerId,
            7
        );

        $first = $this->getPage();

        $this->assertSame(200, $first['status']);
        $this->assertSame(20, $first['data']['count']);
        $this->assertSame(18, $first['data']['unreadCount']);

        $second = $this->getPage(
            $first['data']['nextCursor']
        );

        $this->assertSame(200, $second['status']);
        $this->assertSame(5, $second['data']['count']);

        // El contador debe representar todas las paginas.
        $this->assertSame(
            18,
            $second['data']['unreadCount']
        );
    }

    public function test_same_timestamp_does_not_skip_notifications(): void
    {
        $createdIds = $this->createNotifications(
            25,
            $this->buyerId,
            0,
            true
        );

        $first = $this->getPage();

        $this->assertSame(200, $first['status']);
        $this->assertSame(20, $first['data']['count']);
        $this->assertTrue($first['data']['hasMore']);

        $second = $this->getPage(
            $first['data']['nextCursor']
        );

        $this->assertSame(200, $second['status']);
        $this->assertSame(5, $second['data']['count']);

        $ids = array_merge(
            array_column(
                $first['data']['notifications'],
                'id'
            ),
            array_column(
                $second['data']['notifications'],
                'id'
            )
        );

        $this->assertEqualsCanonicalizing(
            $createdIds,
            $ids
        );

        $this->assertCount(
            25,
            array_unique($ids)
        );
    }

    public function test_pagination_never_includes_another_buyer(): void
    {
        $ownIds = $this->createNotifications(21);

        $otherIds = $this->createNotifications(
            5,
            $this->otherBuyerId
        );

        $first = $this->getPage();

        $second = $this->getPage(
            $first['data']['nextCursor']
        );

        $ids = array_merge(
            array_column(
                $first['data']['notifications'],
                'id'
            ),
            array_column(
                $second['data']['notifications'],
                'id'
            )
        );

        $this->assertEqualsCanonicalizing(
            $ownIds,
            $ids
        );

        foreach ($otherIds as $id) {
            $this->assertNotContains($id, $ids);
        }

        $this->assertSame(
            21,
            $first['data']['unreadCount']
        );
    }

    public function test_invalid_cursor_is_rejected(): void
    {
        $invalidCursors = [
            '!',
            'invalid-cursor',
            str_repeat('a', 513),
            base64_encode('not-json'),
        ];

        foreach ($invalidCursors as $cursor) {
            $result = $this->getPage($cursor);

            $this->assertSame(
                422,
                $result['status']
            );

            $this->assertArrayNotHasKey(
                'notifications',
                $result['data']
            );
        }
    }

    public function test_empty_list_has_no_next_page(): void
    {
        $result = $this->getPage();

        $this->assertSame(200, $result['status']);

        $this->assertSame(
            [],
            $result['data']['notifications']
        );

        $this->assertSame(0, $result['data']['count']);
        $this->assertSame(0, $result['data']['unreadCount']);
        $this->assertFalse($result['data']['hasMore']);
        $this->assertNull($result['data']['nextCursor']);
    }

    public function test_twenty_notifications_have_no_next_page(): void
    {
        $this->createNotifications(20);

        $result = $this->getPage();

        $this->assertSame(200, $result['status']);
        $this->assertSame(20, $result['data']['count']);
        $this->assertFalse($result['data']['hasMore']);
        $this->assertNull($result['data']['nextCursor']);
    }

    /**
     * Eliminar los documentos temporales del emulador.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                foreach ($this->createdIds as $id) {
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
