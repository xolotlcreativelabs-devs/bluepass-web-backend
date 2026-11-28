<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SellerReviewController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class SellerReviewEdgeCasesTest extends TestCase
{
    private FirestoreClient $firestore;
    private SellerReviewController $controller;
    private string $eventId;
    private string $sellerId;

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutar solamente en Firestore Emulator.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail('Estas pruebas requieren Firestore Emulator.');
        }

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        $this->controller = new SellerReviewController();

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'hu09-edge-event-' . $suffix;
        $this->sellerId = 'hu09-edge-seller-' . $suffix;

        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->set([
                'name' => 'Evento de prueba',
                'sellerId' => $this->sellerId,
                'status' => 'published',
            ]);
    }

    private function createReview(
        string $id,
        string $date,
        array $overrides = []
    ): void {
        $data = array_merge([
            'eventId' => $this->eventId,
            'userId' => $id,
            'sellerId' => $this->sellerId,
            'authorName' => 'Comprador',
            'rating' => 5,
            'comment' => 'Excelente evento de prueba.',
            'status' => 'published',
            'createdAt' => new Timestamp(
                new DateTimeImmutable($date)
            ),
            'updatedAt' => new Timestamp(
                new DateTimeImmutable($date)
            ),
        ], $overrides);

        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reviews')
            ->document($id)
            ->set($data);
    }

    private function consult(
        int $page = 1,
        int $limit = 20
    ): array {
        $request = Request::create(
            '/api/seller/events/' . $this->eventId . '/reviews',
            'GET',
            [],
            [],
            [],
            [],
            null
        );

        $request->query->set('page', (string) $page);
        $request->query->set('limit', (string) $limit);

        $request->attributes->set(
            'firebase_uid',
            $this->sellerId
        );

        $request->attributes->set(
            'firebase_role',
            'seller'
        );

        $response = $this->controller->index(
            $request,
            $this->eventId,
            $this->firestore
        );

        $this->assertSame(
            200,
            $response->getStatusCode(),
            $response->getContent()
        );

        return $response->getData(true);
    }

    public function test_event_without_reviews_returns_empty_list(): void
    {
        $body = $this->consult();

        $this->assertSame([], $body['reviews']);
        $this->assertFalse($body['pagination']['hasMore']);
        $this->assertNull($body['pagination']['nextPage']);
    }

    public function test_invalid_review_does_not_take_page_space(): void
    {
        $this->createReview('buyer-1', '-4 days');
        $this->createReview('buyer-invalid', '-3 days', [
            'sellerId' => 'another-seller',
        ]);
        $this->createReview('buyer-2', '-2 days');
        $this->createReview('buyer-3', '-1 day');

        $first = $this->consult(1, 2);

        $this->assertSame(
            ['buyer-3', 'buyer-2'],
            array_column($first['reviews'], 'id')
        );

        $this->assertTrue($first['pagination']['hasMore']);
        $this->assertSame(2, $first['pagination']['nextPage']);

        $second = $this->consult(2, 2);

        $this->assertSame(
            ['buyer-1'],
            array_column($second['reviews'], 'id')
        );

        $this->assertFalse($second['pagination']['hasMore']);
        $this->assertNull($second['pagination']['nextPage']);
    }

    public function test_inconsistent_event_and_user_are_excluded(): void
    {
        $this->createReview('buyer-valid', '-3 days');

        $this->createReview('buyer-bad-event', '-2 days', [
            'eventId' => 'another-event',
        ]);

        $this->createReview('buyer-bad-user', '-1 day', [
            'userId' => 'another-user',
        ]);

        $body = $this->consult();

        $this->assertSame(
            ['buyer-valid'],
            array_column($body['reviews'], 'id')
        );
    }

    public function test_empty_page_after_last_page_is_valid(): void
    {
        $this->createReview('buyer-only', '-1 day');

        $body = $this->consult(3, 2);

        $this->assertSame([], $body['reviews']);
        $this->assertFalse($body['pagination']['hasMore']);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore, $this->eventId)) {
                $event = $this->firestore
                    ->collection('events')
                    ->document($this->eventId);

                foreach (
                    $event->collection('reviews')->documents()
                    as $document
                ) {
                    if ($document->exists()) {
                        $document->reference()->delete();
                    }
                }

                $event->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
