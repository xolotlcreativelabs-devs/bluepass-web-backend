<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReviewController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReviewControllerTest extends TestCase
{
    private FirestoreClient $firestore;
    private ReviewController $controller;

    private string $eventId;
    private string $orderId;
    private string $ticketId;
    private string $buyerId;
    private string $sellerId;
    private string $paymentReference;

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutar solamente con Firestore Emulator.
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

        $this->controller = new ReviewController();

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'hu09-event-' . $suffix;
        $this->orderId = 'hu09-order-' . $suffix;
        $this->ticketId = 'hu09-ticket-' . $suffix;
        $this->buyerId = 'hu09-buyer-' . $suffix;
        $this->sellerId = 'hu09-seller-' . $suffix;
        $this->paymentReference = 'hu09-payment-' . $suffix;

        $now = new DateTimeImmutable();

        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->set([
                'name' => 'Concierto de prueba HU09',
                'status' => 'published',
                'visibility' => 'public',
                'isPrivate' => false,
                'sellerId' => $this->sellerId,
                'date' => new Timestamp($now->modify('-5 days')),
                'endAt' => new Timestamp($now->modify('-4 days')),
            ]);

        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->set([
                'userId' => $this->buyerId,
                'eventId' => $this->eventId,
                'sellerId' => $this->sellerId,
                'status' => 'paid',
                'paymentReference' => $this->paymentReference,
                'issuedTicketIds' => [$this->ticketId],
                'paidAt' => new Timestamp($now),
            ]);

        $this->firestore
            ->collection('tickets')
            ->document($this->ticketId)
            ->set([
                'userId' => $this->buyerId,
                'eventId' => $this->eventId,
                'orderId' => $this->orderId,
                'ticketTypeId' => 'general',
                'status' => 'valid',
                'issuedAt' => new Timestamp($now),
            ]);

        $this->firestore
            ->collection('paymentReferences')
            ->document(hash('sha256', $this->paymentReference))
            ->set([
                'orderId' => $this->orderId,
                'paymentReference' => $this->paymentReference,
                'createdAt' => new Timestamp($now),
            ]);
    }

    private function request(
        string $method = 'POST',
        ?string $uid = null,
        array $data = [
            'rating' => 5,
            'comment' => 'Excelente concierto BluePass.',
        ],
        string $role = 'customer'
    ): Request {
        $request = Request::create(
            '/api/events/' . $this->eventId . '/reviews',
            $method,
            $data
        );

        $request->attributes->set(
            'firebase_uid',
            $uid ?? $this->buyerId
        );

        $request->attributes->set(
            'firebase_role',
            $role
        );

        $request->attributes->set(
            'firebase_profile',
            ['name' => 'Comprador de prueba']
        );

        return $request;
    }

    private function publish(
        ?string $uid = null,
        array $data = [
            'rating' => 5,
            'comment' => 'Excelente concierto BluePass.',
        ]
    ) {
        return $this->controller->store(
            $this->request('POST', $uid, $data),
            $this->eventId,
            $this->firestore
        );
    }

    private function edit(
        array $data = [
            'rating' => 4,
            'comment' => 'Muy buena experiencia.',
        ],
        ?string $uid = null
    ) {
        return $this->controller->update(
            $this->request('PATCH', $uid, $data),
            $this->eventId,
            $this->firestore
        );
    }

    private function reviewSnapshot()
    {
        return $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reviews')
            ->document($this->buyerId)
            ->snapshot();
    }

    public function test_paid_buyer_can_publish_review(): void
    {
        $response = $this->publish();

        $this->assertSame(
            201,
            $response->getStatusCode(),
            $response->getContent()
        );

        $body = $response->getData(true);

        $this->assertSame(5, $body['review']['rating']);
        $this->assertSame('published', $body['review']['status']);

        $snapshot = $this->reviewSnapshot();

        $this->assertTrue($snapshot->exists());

        $stored = $snapshot->data();

        $this->assertSame($this->buyerId, $stored['userId']);
        $this->assertSame($this->sellerId, $stored['sellerId']);
    }

    public function test_duplicate_review_is_rejected(): void
    {
        $first = $this->publish();
        $second = $this->publish();

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(409, $second->getStatusCode());
    }

    public function test_buyer_can_edit_own_review(): void
    {
        $this->assertSame(
            201,
            $this->publish()->getStatusCode()
        );

        $response = $this->edit();

        $this->assertSame(
            200,
            $response->getStatusCode(),
            $response->getContent()
        );

        $stored = $this->reviewSnapshot()->data();

        $this->assertSame(4, $stored['rating']);
        $this->assertSame(
            'Muy buena experiencia.',
            $stored['comment']
        );
    }

    public function test_cannot_edit_nonexistent_review(): void
    {
        $response = $this->edit();

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_pending_order_cannot_publish(): void
    {
        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->update([
                ['path' => 'status', 'value' => 'pending'],
            ]);

        $response = $this->publish();

        $this->assertSame(
            403,
            $response->getStatusCode(),
            $response->getContent()
        );

        $this->assertFalse($this->reviewSnapshot()->exists());
    }

    public function test_other_buyer_cannot_publish(): void
    {
        $response = $this->publish('other-' . $this->buyerId);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->reviewSnapshot()->exists());
    }

    public function test_cancelled_ticket_cannot_publish(): void
    {
        $this->firestore
            ->collection('tickets')
            ->document($this->ticketId)
            ->update([
                ['path' => 'status', 'value' => 'cancelled'],
            ]);

        $response = $this->publish();

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_used_ticket_can_publish(): void
    {
        $this->firestore
            ->collection('tickets')
            ->document($this->ticketId)
            ->update([
                ['path' => 'status', 'value' => 'used'],
            ]);

        $response = $this->publish();

        $this->assertSame(
            201,
            $response->getStatusCode(),
            $response->getContent()
        );
    }

    public function test_future_event_cannot_be_reviewed(): void
    {
        $now = new DateTimeImmutable();

        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->update([
                [
                    'path' => 'date',
                    'value' => new Timestamp($now->modify('+3 days')),
                ],
                [
                    'path' => 'endAt',
                    'value' => new Timestamp($now->modify('+4 days')),
                ],
            ]);

        $response = $this->publish();

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_invalid_rating_is_rejected(): void
    {
        foreach ([0, 6, 3.5, '5'] as $rating) {
            $response = $this->publish(
                null,
                [
                    'rating' => $rating,
                    'comment' => 'Comentario de prueba valido.',
                ]
            );

            $this->assertSame(422, $response->getStatusCode());
        }
    }

    public function test_short_comment_is_rejected(): void
    {
        $response = $this->publish(
            null,
            [
                'rating' => 5,
                'comment' => 'Bien',
            ]
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_editing_hidden_review_keeps_it_hidden(): void
    {
        $this->assertSame(
            201,
            $this->publish()->getStatusCode()
        );

        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reviews')
            ->document($this->buyerId)
            ->update([
                ['path' => 'status', 'value' => 'hidden'],
            ]);

        $response = $this->edit();

        $this->assertSame(
            200,
            $response->getStatusCode(),
            $response->getContent()
        );

        $stored = $this->reviewSnapshot()->data();

        $this->assertSame('hidden', $stored['status']);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                $this->firestore
                    ->collection('events')
                    ->document($this->eventId)
                    ->collection('reviews')
                    ->document($this->buyerId)
                    ->delete();

                $this->firestore
                    ->collection('paymentReferences')
                    ->document(hash('sha256', $this->paymentReference))
                    ->delete();

                $this->firestore
                    ->collection('tickets')
                    ->document($this->ticketId)
                    ->delete();

                $this->firestore
                    ->collection('orders')
                    ->document($this->orderId)
                    ->delete();

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
