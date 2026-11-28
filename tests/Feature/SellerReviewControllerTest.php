<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SellerReviewController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class SellerReviewControllerTest extends TestCase
{
    private FirestoreClient $firestore;
    private SellerReviewController $controller;

    private string $eventId;
    private string $sellerId;
    private string $otherSellerId;

    protected function setUp(): void
    {
        parent::setUp();

        // Utilizar exclusivamente Firestore Emulator.
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

        $this->controller = new SellerReviewController();

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'hu09-seller-event-' . $suffix;
        $this->sellerId = 'hu09-seller-' . $suffix;
        $this->otherSellerId = 'hu09-other-seller-' . $suffix;

        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->set([
                'name' => 'Evento de prueba para vendedor',
                'sellerId' => $this->sellerId,
                'status' => 'published',
            ]);

        $this->createReview(
            'buyer-1',
            5,
            'Excelente experiencia con BluePass.',
            'published',
            '-3 days'
        );

        $this->createReview(
            'buyer-2',
            3,
            'La experiencia pudo ser mejor.',
            'hidden',
            '-2 days'
        );

        $this->createReview(
            'buyer-3',
            4,
            'Muy buen evento y organizacion.',
            'published',
            '-1 day'
        );
    }

    private function createReview(
        string $buyerId,
        int $rating,
        string $comment,
        string $status,
        string $date
    ): void {
        $createdAt = new Timestamp(
            new DateTimeImmutable($date)
        );

        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reviews')
            ->document($buyerId)
            ->set([
                'eventId' => $this->eventId,
                'userId' => $buyerId,
                'sellerId' => $this->sellerId,
                'authorName' => 'Comprador ' . $buyerId,
                'rating' => $rating,
                'comment' => $comment,
                'status' => $status,
                'createdAt' => $createdAt,
                'updatedAt' => $createdAt,
            ]);
    }

    private function request(
        ?string $uid = null,
        string $role = 'seller',
        array $query = []
    ): Request {
        $url = '/api/seller/events/' . $this->eventId . '/reviews';

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $request = Request::create($url, 'GET');

        $request->attributes->set(
            'firebase_uid',
            $uid ?? $this->sellerId
        );

        $request->attributes->set(
            'firebase_role',
            $role
        );

        return $request;
    }

    private function consult(
        ?string $uid = null,
        string $role = 'seller',
        array $query = [],
        ?string $eventId = null
    ) {
        return $this->controller->index(
            $this->request($uid, $role, $query),
            $eventId ?? $this->eventId,
            $this->firestore
        );
    }

    public function test_seller_can_consult_own_event_reviews(): void
    {
        $response = $this->consult();

        $this->assertSame(
            200,
            $response->getStatusCode(),
            $response->getContent()
        );

        $body = $response->getData(true);

        $this->assertSame($this->eventId, $body['eventId']);
        $this->assertCount(3, $body['reviews']);
        $this->assertFalse($body['pagination']['hasMore']);
    }

    public function test_seller_can_see_hidden_reviews(): void
    {
        $response = $this->consult();
        $body = $response->getData(true);

        $statuses = array_column(
            $body['reviews'],
            'status'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('hidden', $statuses);
        $this->assertContains('published', $statuses);
    }

    public function test_seller_cannot_consult_another_sellers_event(): void
    {
        $response = $this->consult(
            $this->otherSellerId
        );

        $this->assertSame(
            403,
            $response->getStatusCode()
        );
    }

    public function test_customer_cannot_consult_seller_reviews(): void
    {
        $response = $this->consult(
            'buyer-1',
            'customer'
        );

        $this->assertSame(
            403,
            $response->getStatusCode()
        );
    }

    public function test_admin_cannot_use_seller_only_endpoint(): void
    {
        $response = $this->consult(
            'admin-test',
            'admin'
        );

        $this->assertSame(
            403,
            $response->getStatusCode()
        );
    }

    public function test_missing_identity_is_rejected(): void
    {
        $request = Request::create(
            '/api/seller/events/' . $this->eventId . '/reviews',
            'GET'
        );

        $response = $this->controller->index(
            $request,
            $this->eventId,
            $this->firestore
        );

        $this->assertSame(
            401,
            $response->getStatusCode()
        );
    }

    public function test_nonexistent_event_returns_404(): void
    {
        $response = $this->consult(
            null,
            'seller',
            [],
            'nonexistent-event'
        );

        $this->assertSame(
            404,
            $response->getStatusCode()
        );
    }

    public function test_invalid_query_parameters_are_rejected(): void
    {
        $queries = [
            ['page' => '0'],
            ['page' => '-1'],
            ['limit' => '0'],
            ['limit' => '51'],
            ['page' => 'abc'],
            ['limit' => 'abc'],
            ['unexpected' => 'value'],
        ];

        foreach ($queries as $query) {
            $response = $this->consult(
                null,
                'seller',
                $query
            );

            $this->assertSame(
                422,
                $response->getStatusCode(),
                $response->getContent()
            );
        }
    }

    public function test_reviews_are_paginated(): void
    {
        $first = $this->consult(
            null,
            'seller',
            ['page' => '1', 'limit' => '2']
        );

        $this->assertSame(200, $first->getStatusCode());

        $firstBody = $first->getData(true);

        $this->assertCount(2, $firstBody['reviews']);
        $this->assertTrue($firstBody['pagination']['hasMore']);
        $this->assertSame(2, $firstBody['pagination']['nextPage']);

        $second = $this->consult(
            null,
            'seller',
            ['page' => '2', 'limit' => '2']
        );

        $this->assertSame(200, $second->getStatusCode());

        $secondBody = $second->getData(true);

        $this->assertCount(1, $secondBody['reviews']);
        $this->assertFalse($secondBody['pagination']['hasMore']);
        $this->assertNull($secondBody['pagination']['nextPage']);
    }

    public function test_reviews_are_ordered_from_newest_to_oldest(): void
    {
        $response = $this->consult();

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $ids = array_column(
            $body['reviews'],
            'id'
        );

        $this->assertSame(
            ['buyer-3', 'buyer-2', 'buyer-1'],
            $ids
        );
    }

    public function test_invalid_event_id_is_rejected(): void
    {
        $response = $this->consult(
            null,
            'seller',
            [],
            'evento invalido!'
        );

        $this->assertSame(
            404,
            $response->getStatusCode()
        );
    }

    public function test_review_dates_are_returned(): void
    {
        $response = $this->consult();

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        foreach ($body['reviews'] as $review) {
            $this->assertNotNull($review['createdAt']);
            $this->assertNotNull($review['updatedAt']);
        }
    }

    protected function tearDown(): void
    {
        try {
            if (
                isset($this->firestore, $this->eventId)
            ) {
                $reviews = $this->firestore
                    ->collection('events')
                    ->document($this->eventId)
                    ->collection('reviews');

                foreach ($reviews->documents() as $document) {
                    if ($document->exists()) {
                        $document->reference()->delete();
                    }
                }

                $this->firestore
                    ->collection('events')
                    ->document($this->eventId)
                    ->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
