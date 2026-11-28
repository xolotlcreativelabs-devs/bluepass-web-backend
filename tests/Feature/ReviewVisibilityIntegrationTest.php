<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PastEventController;
use App\Http\Controllers\Api\ReviewModerationController;
use App\Http\Controllers\Api\SellerReviewController;
use App\Services\AuditLogger;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReviewVisibilityIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;
    private PastEventController $pastController;
    private SellerReviewController $sellerController;
    private ReviewModerationController $moderationController;
    private AuditLogger $auditLogger;

    private string $eventId;
    private string $reviewId;
    private string $sellerId;
    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        // No permitir pruebas contra Firebase real.
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

        $this->pastController = new PastEventController();
        $this->sellerController = new SellerReviewController();
        $this->moderationController = new ReviewModerationController();
        $this->auditLogger = new AuditLogger();

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'hu09-integration-event-' . $suffix;
        $this->reviewId = 'hu09-integration-buyer-' . $suffix;
        $this->sellerId = 'hu09-integration-seller-' . $suffix;
        $this->adminId = 'hu09-integration-admin-' . $suffix;

        $now = new DateTimeImmutable();

        // Crear un evento publico que ya finalizo.
        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->set([
                'name' => 'Evento de integracion BluePass',
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

        // Crear una resena inicialmente publicada.
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
                'comment' => 'Excelente experiencia en BluePass.',
                'status' => 'published',
                'createdAt' => new Timestamp($now),
                'updatedAt' => new Timestamp($now),
            ]);
    }

    private function publicReviews(): array
    {
        $request = Request::create(
            '/api/events/' . $this->eventId . '/reviews',
            'GET'
        );

        $response = $this->pastController->reviews(
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

    private function sellerReviews(): array
    {
        $request = Request::create(
            '/api/seller/events/' . $this->eventId . '/reviews',
            'GET'
        );

        $request->attributes->set(
            'firebase_uid',
            $this->sellerId
        );

        $request->attributes->set(
            'firebase_role',
            'seller'
        );

        $response = $this->sellerController->index(
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

    private function hideReview(): void
    {
        $request = Request::create(
            '/api/admin/events/' .
                $this->eventId .
                '/reviews/' .
                $this->reviewId .
                '/hide',
            'PATCH'
        );

        $request->attributes->set(
            'firebase_uid',
            $this->adminId
        );

        $request->attributes->set(
            'firebase_role',
            'admin'
        );

        $response = $this->moderationController->hide(
            $request,
            $this->eventId,
            $this->reviewId,
            $this->firestore,
            $this->auditLogger
        );

        $this->assertSame(
            200,
            $response->getStatusCode(),
            $response->getContent()
        );
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

    public function test_hidden_review_is_removed_from_public_view_but_visible_to_seller(): void
    {
        // Antes de moderar, ambas consultas ven la resena.
        $publicBefore = $this->publicReviews();
        $sellerBefore = $this->sellerReviews();

        $this->assertCount(1, $publicBefore['reviews']);
        $this->assertCount(1, $sellerBefore['reviews']);

        $this->assertSame(
            $this->reviewId,
            $publicBefore['reviews'][0]['id']
        );

        $this->assertSame(
            'published',
            $sellerBefore['reviews'][0]['status']
        );

        // El administrador oculta la resena.
        $this->hideReview();

        // La consulta publica ya no debe mostrarla.
        $publicAfter = $this->publicReviews();

        $this->assertSame([], $publicAfter['reviews']);
        $this->assertSame(
            0,
            $publicAfter['pagination']['matchedInWindow']
        );

        // El vendedor conserva acceso al registro moderado.
        $sellerAfter = $this->sellerReviews();

        $this->assertCount(1, $sellerAfter['reviews']);

        $this->assertSame(
            $this->reviewId,
            $sellerAfter['reviews'][0]['id']
        );

        $this->assertSame(
            'hidden',
            $sellerAfter['reviews'][0]['status']
        );

        // Firestore debe conservar el estado oculto.
        $snapshot = $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reviews')
            ->document($this->reviewId)
            ->snapshot();

        $this->assertSame(
            'hidden',
            $snapshot->data()['status']
        );

        // Debe existir una auditoria de la moderacion.
        $audits = $this->auditDocuments();

        $this->assertCount(1, $audits);

        $audit = $audits[0]->data();

        $this->assertSame('hidden', $audit['action']);
        $this->assertSame('reviews', $audit['resourceType']);
        $this->assertSame($this->adminId, $audit['actorId']);
        $this->assertSame('admin', $audit['actorRole']);
        $this->assertSame(
            ['status'],
            $audit['changedFields']
        );
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore, $this->eventId)) {
                // Eliminar auditorias creadas en esta prueba.
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
