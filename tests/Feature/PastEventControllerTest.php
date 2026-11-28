<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PastEventController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class PastEventControllerTest extends TestCase
{
    private FirestoreClient $firestore;

    private PastEventController $controller;

    private string $suffix;

    private string $pastId;

    private string $futureId;

    private string $privateId;

    private string $draftId;

    private array $eventIds = [];

    private array $reviewIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Impedir el uso accidental de Firebase real.
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

        $this->controller = new PastEventController();

        $this->suffix = bin2hex(random_bytes(8));

        $this->pastId = 'hu09-past-' . $this->suffix;
        $this->futureId = 'hu09-future-' . $this->suffix;
        $this->privateId = 'hu09-private-' . $this->suffix;
        $this->draftId = 'hu09-draft-' . $this->suffix;

        $now = new DateTimeImmutable();

        $this->createEvent(
            $this->pastId,
            'published',
            false,
            $now->modify('-5 days'),
            $now->modify('-4 days')
        );

        $this->createEvent(
            $this->futureId,
            'published',
            false,
            $now->modify('+5 days'),
            $now->modify('+6 days')
        );

        $this->createEvent(
            $this->privateId,
            'published',
            true,
            $now->modify('-5 days'),
            $now->modify('-4 days')
        );

        $this->createEvent(
            $this->draftId,
            'draft',
            false,
            $now->modify('-5 days'),
            $now->modify('-4 days')
        );

        $this->createReview(
            'publica-uno',
            'published',
            5,
            'Excelente experiencia.',
            $now->modify('-3 days')
        );

        $this->createReview(
            'publica-dos',
            'published',
            4,
            'Muy buena organizacion.',
            $now->modify('-2 days')
        );

        $this->createReview(
            'oculta',
            'hidden',
            1,
            'Resena oculta de prueba.',
            $now->modify('-1 day')
        );
    }

    /**
     * Crear un evento temporal en Firestore Emulator.
     */
    private function createEvent(
        string $id,
        string $status,
        bool $private,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): void {
        $this->firestore
            ->collection('events')
            ->document($id)
            ->set([
                'name' => 'Festival Hu09 ' . $this->suffix,
                'description' => 'Evento de prueba.',
                'category' => 'Musica',
                'venue' => 'Auditorio de prueba',
                'timezone' => 'America/Mexico_City',
                'date' => new Timestamp($start),
                'endAt' => new Timestamp($end),
                'status' => $status,
                'visibility' => $private
                    ? 'private'
                    : 'public',
                'isPrivate' => $private,
                'imageUrl' => null,
            ]);

        $this->eventIds[] = $id;
    }

    /**
     * Crear una resena temporal.
     */
    private function createReview(
        string $id,
        string $status,
        int $rating,
        string $comment,
        DateTimeImmutable $createdAt
    ): void {
        $reviewId = 'hu09-' . $id . '-' . $this->suffix;

        $this->firestore
            ->collection('events')
            ->document($this->pastId)
            ->collection('reviews')
            ->document($reviewId)
            ->set([
                'eventId' => $this->pastId,
                'userId' => 'comprador-' . $this->suffix,
                'sellerId' => 'vendedor-' . $this->suffix,
                'authorName' => 'Comprador BluePass',
                'rating' => $rating,
                'comment' => $comment,
                'status' => $status,
                'createdAt' => new Timestamp($createdAt),
                'updatedAt' => new Timestamp($createdAt),
            ]);

        $this->reviewIds[] = $reviewId;
    }

    private function request(
        string $path,
        array $parameters = []
    ): Request {
        return Request::create(
            $path,
            'GET',
            $parameters
        );
    }

    public function test_lists_past_public_events(): void
    {
        $response = $this->controller->index(
            $this->request(
                '/api/events/past',
                ['search' => $this->suffix]
            ),
            $this->firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $ids = array_column($body['events'], 'id');

        $this->assertContains($this->pastId, $ids);
        $this->assertNotContains($this->futureId, $ids);
        $this->assertNotContains($this->privateId, $ids);
        $this->assertNotContains($this->draftId, $ids);
    }

    public function test_can_view_past_event_details(): void
    {
        $response = $this->controller->show(
            $this->pastId,
            $this->firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $event = $response->getData(true)['event'];

        $this->assertSame($this->pastId, $event['id']);
        $this->assertTrue($event['isPast']);
        $this->assertSame('published', $event['status']);
    }

    public function test_future_event_is_not_a_past_event(): void
    {
        $response = $this->controller->show(
            $this->futureId,
            $this->firestore
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_private_event_is_not_public(): void
    {
        $response = $this->controller->show(
            $this->privateId,
            $this->firestore
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_draft_event_is_not_public(): void
    {
        $response = $this->controller->show(
            $this->draftId,
            $this->firestore
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_lists_only_published_reviews(): void
    {
        $response = $this->controller->reviews(
            $this->request(
                '/api/events/' . $this->pastId . '/reviews'
            ),
            $this->pastId,
            $this->firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertCount(2, $body['reviews']);

        $ratings = array_column($body['reviews'], 'rating');

        $this->assertContains(5, $ratings);
        $this->assertContains(4, $ratings);
        $this->assertNotContains(1, $ratings);

        foreach ($body['reviews'] as $review) {
            $this->assertArrayNotHasKey(
                'userId',
                $review
            );

            $this->assertArrayNotHasKey(
                'sellerId',
                $review
            );
        }
    }

    public function test_reviews_support_pagination(): void
    {
        $response = $this->controller->reviews(
            $this->request(
                '/api/events/' . $this->pastId . '/reviews',
                [
                    'page' => 1,
                    'perPage' => 1,
                ]
            ),
            $this->pastId,
            $this->firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertCount(1, $body['reviews']);

        $this->assertTrue(
            $body['pagination']['hasNextPage']
        );

        $this->assertSame(
            2,
            $body['pagination']['matchedInWindow']
        );
    }

    public function test_rejects_invalid_query_parameters(): void
    {
        $events = $this->controller->index(
            $this->request(
                '/api/events/past',
                ['perPage' => 100]
            ),
            $this->firestore
        );

        $reviews = $this->controller->reviews(
            $this->request(
                '/api/events/' . $this->pastId . '/reviews',
                ['userId' => 'otro-comprador']
            ),
            $this->pastId,
            $this->firestore
        );

        $this->assertSame(422, $events->getStatusCode());
        $this->assertSame(422, $reviews->getStatusCode());
    }

    public function test_rejects_invalid_event_id(): void
    {
        $response = $this->controller->show(
            'evento-invalido!',
            $this->firestore
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_future_event_does_not_expose_reviews(): void
    {
        $response = $this->controller->reviews(
            $this->request(
                '/api/events/' . $this->futureId . '/reviews'
            ),
            $this->futureId,
            $this->firestore
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Eliminar los documentos temporales del emulador.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                foreach ($this->reviewIds as $reviewId) {
                    $this->firestore
                        ->collection('events')
                        ->document($this->pastId)
                        ->collection('reviews')
                        ->document($reviewId)
                        ->delete();
                }

                foreach ($this->eventIds as $eventId) {
                    $this->firestore
                        ->collection('events')
                        ->document($eventId)
                        ->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
