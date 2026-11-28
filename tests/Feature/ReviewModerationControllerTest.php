<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReviewModerationController;
use App\Services\AuditLogger;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReviewModerationControllerTest extends TestCase
{
    private FirestoreClient $firestore;
    private ReviewModerationController $controller;
    private AuditLogger $auditLogger;

    private string $eventId;
    private string $reviewId;
    private string $adminId;
    private string $sellerId;

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca ejecutar estas pruebas en Firebase real.
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

        $this->controller = new ReviewModerationController();
        $this->auditLogger = new AuditLogger();

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'hu09-moderation-event-' . $suffix;
        $this->reviewId = 'hu09-buyer-' . $suffix;
        $this->adminId = 'hu09-admin-' . $suffix;
        $this->sellerId = 'hu09-seller-' . $suffix;

        $now = new DateTimeImmutable();

        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->set([
                'name' => 'Evento de moderacion BluePass',
                'sellerId' => $this->sellerId,
                'status' => 'published',
                'visibility' => 'public',
                'isPrivate' => false,
                'date' => new Timestamp(
                    $now->modify('-5 days')
                ),
                'endAt' => new Timestamp(
                    $now->modify('-4 days')
                ),
            ]);

        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reviews')
            ->document($this->reviewId)
            ->set([
                'eventId' => $this->eventId,
                'userId' => $this->reviewId,
                'sellerId' => $this->sellerId,
                'authorName' => 'Comprador de prueba',
                'rating' => 5,
                'comment' => 'Excelente experiencia BluePass.',
                'status' => 'published',
                'createdAt' => new Timestamp($now),
                'updatedAt' => new Timestamp($now),
            ]);
    }

    private function request(
        ?string $uid = null,
        string $role = 'admin',
        array $data = [],
        array $query = []
    ): Request {
        $url = '/api/admin/events/' .
            $this->eventId .
            '/reviews/' .
            $this->reviewId .
            '/hide';

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $request = Request::create(
            $url,
            'PATCH',
            $data
        );

        $request->attributes->set(
            'firebase_uid',
            $uid ?? $this->adminId
        );

        $request->attributes->set(
            'firebase_role',
            $role
        );

        return $request;
    }

    private function hide(
        ?string $uid = null,
        string $role = 'admin',
        array $data = [],
        array $query = [],
        ?string $eventId = null,
        ?string $reviewId = null
    ) {
        return $this->controller->hide(
            $this->request($uid, $role, $data, $query),
            $eventId ?? $this->eventId,
            $reviewId ?? $this->reviewId,
            $this->firestore,
            $this->auditLogger
        );
    }

    private function reviewData(): array
    {
        return $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reviews')
            ->document($this->reviewId)
            ->snapshot()
            ->data();
    }

    private function auditDocuments(): array
    {
        $resourceId = $this->eventId . ':' . $this->reviewId;

        $documents = $this->firestore
            ->collection('audit_logs')
            ->where('resourceId', '=', $resourceId)
            ->documents();

        $results = [];

        foreach ($documents as $document) {
            if ($document->exists()) {
                $results[] = $document;
            }
        }

        return $results;
    }

    public function test_admin_can_hide_published_review(): void
    {
        $response = $this->hide();

        $this->assertSame(
            200,
            $response->getStatusCode(),
            $response->getContent()
        );

        $body = $response->getData(true);

        $this->assertSame(
            'hidden',
            $body['review']['status']
        );

        $this->assertSame(
            'hidden',
            $this->reviewData()['status']
        );
    }

    public function test_hiding_review_creates_audit_record(): void
    {
        $this->assertSame(
            200,
            $this->hide()->getStatusCode()
        );

        $documents = $this->auditDocuments();

        $this->assertCount(1, $documents);

        $audit = $documents[0]->data();

        $this->assertSame('hidden', $audit['action']);
        $this->assertSame('reviews', $audit['resourceType']);
        $this->assertSame($this->adminId, $audit['actorId']);
        $this->assertSame('admin', $audit['actorRole']);
        $this->assertSame(
            ['status'],
            $audit['changedFields']
        );
        $this->assertNotNull($audit['createdAt']);
    }

    public function test_seller_cannot_hide_review(): void
    {
        $response = $this->hide(
            $this->sellerId,
            'seller'
        );

        $this->assertSame(403, $response->getStatusCode());

        $this->assertSame(
            'published',
            $this->reviewData()['status']
        );

        $this->assertCount(0, $this->auditDocuments());
    }

    public function test_customer_cannot_hide_review(): void
    {
        $response = $this->hide(
            $this->reviewId,
            'customer'
        );

        $this->assertSame(403, $response->getStatusCode());

        $this->assertSame(
            'published',
            $this->reviewData()['status']
        );
    }

    public function test_missing_identity_is_rejected(): void
    {
        $request = Request::create(
            '/api/admin/events/' .
                $this->eventId .
                '/reviews/' .
                $this->reviewId .
                '/hide',
            'PATCH'
        );

        $response = $this->controller->hide(
            $request,
            $this->eventId,
            $this->reviewId,
            $this->firestore,
            $this->auditLogger
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_nonexistent_event_returns_404(): void
    {
        $response = $this->hide(
            null,
            'admin',
            [],
            [],
            'nonexistent-event'
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_nonexistent_review_returns_404(): void
    {
        $response = $this->hide(
            null,
            'admin',
            [],
            [],
            null,
            'nonexistent-review'
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_hidden_review_cannot_be_hidden_twice(): void
    {
        $first = $this->hide();
        $second = $this->hide();

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(409, $second->getStatusCode());

        // La segunda solicitud no crea otra auditoria.
        $this->assertCount(1, $this->auditDocuments());
    }

    public function test_invalid_identifiers_are_rejected(): void
    {
        $response = $this->hide(
            null,
            'admin',
            [],
            [],
            'evento invalido!'
        );

        $this->assertSame(404, $response->getStatusCode());

        $response = $this->hide(
            null,
            'admin',
            [],
            [],
            null,
            'resena invalida!'
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_additional_fields_are_rejected(): void
    {
        $response = $this->hide(
            null,
            'admin',
            ['status' => 'hidden']
        );

        $this->assertSame(422, $response->getStatusCode());

        $this->assertSame(
            'published',
            $this->reviewData()['status']
        );
    }

    public function test_query_parameters_are_rejected(): void
    {
        $response = $this->hide(
            null,
            'admin',
            [],
            ['force' => 'true']
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_inconsistent_review_is_rejected(): void
    {
        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reviews')
            ->document($this->reviewId)
            ->update([
                [
                    'path' => 'eventId',
                    'value' => 'another-event',
                ],
            ]);

        $response = $this->hide();

        $this->assertSame(409, $response->getStatusCode());

        $this->assertSame(
            'published',
            $this->reviewData()['status']
        );

        $this->assertCount(0, $this->auditDocuments());
    }

    public function test_other_review_status_is_rejected(): void
    {
        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reviews')
            ->document($this->reviewId)
            ->update([
                [
                    'path' => 'status',
                    'value' => 'pending',
                ],
            ]);

        $response = $this->hide();

        $this->assertSame(422, $response->getStatusCode());

        $this->assertCount(0, $this->auditDocuments());
    }

    protected function tearDown(): void
    {
        try {
            if (
                isset(
                    $this->firestore,
                    $this->eventId,
                    $this->reviewId
                )
            ) {
                // Limpiar unicamente los datos de esta prueba.
                foreach ($this->auditDocuments() as $document) {
                    $document->reference()->delete();
                }

                $event = $this->firestore
                    ->collection('events')
                    ->document($this->eventId);

                $event
                    ->collection('reviews')
                    ->document($this->reviewId)
                    ->delete();

                $event->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
