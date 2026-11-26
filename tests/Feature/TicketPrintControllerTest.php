<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketPrintController;
use App\Services\TicketQrImageService;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class TicketPrintControllerTest extends TestCase
{
    private FirestoreClient $firestore;

    private TicketPrintController $controller;

    private TicketQrImageService $qrService;

    private string $buyerId;

    private string $otherBuyerId;

    private string $eventId;

    private string $orderId;

    private string $pendingOrderId;

    private string $qrToken;

    private array $ticketIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Estas pruebas solo pueden usar Firestore Emulator.
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

        $this->controller = new TicketPrintController();
        $this->qrService = new TicketQrImageService();

        $suffix = bin2hex(random_bytes(8));

        $this->buyerId = 'comprador-impresion-' . $suffix;
        $this->otherBuyerId = 'otro-impresion-' . $suffix;
        $this->eventId = 'evento-impresion-' . $suffix;
        $this->orderId = 'pedido-impresion-' . $suffix;
        $this->pendingOrderId = 'pedido-pendiente-' . $suffix;

        $this->qrToken = bin2hex(random_bytes(32));

        $now = new DateTimeImmutable();
        $timestamp = new Timestamp($now);

        $event = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $event->set([
            'name' => 'Concierto BluePass',
            'venue' => 'Auditorio Principal',
            'timezone' => 'America/Mexico_City',
            'date' => new Timestamp(
                $now->modify('+5 days')
            ),
            'endAt' => new Timestamp(
                $now->modify('+5 days +3 hours')
            ),
            'status' => 'published',
        ]);

        $event
            ->collection('ticketTypes')
            ->document('general')
            ->set([
                'name' => 'Entrada General',
                'status' => 'active',
                'price' => 350,
                'currency' => 'MXN',
            ]);

        $orders = $this->firestore->collection('orders');

        $orders
            ->document($this->orderId)
            ->set([
                'userId' => $this->buyerId,
                'eventId' => $this->eventId,
                'status' => 'paid',
                'paidAt' => $timestamp,
                'buyer' => [
                    'name' => 'Comprador BluePass',
                    'email' => 'comprador@example.com',
                ],
            ]);

        $orders
            ->document($this->pendingOrderId)
            ->set([
                'userId' => $this->buyerId,
                'eventId' => $this->eventId,
                'status' => 'pending',
                'paidAt' => null,
                'buyer' => [
                    'name' => 'Comprador BluePass',
                ],
            ]);

        $this->createTicket(
            'valid',
            $suffix,
            $this->buyerId,
            $this->orderId,
            'valid',
            $this->qrToken
        );

        $this->createTicket(
            'other',
            $suffix,
            $this->otherBuyerId,
            $this->orderId,
            'valid',
            bin2hex(random_bytes(32))
        );

        $this->createTicket(
            'used',
            $suffix,
            $this->buyerId,
            $this->orderId,
            'used',
            bin2hex(random_bytes(32))
        );

        $this->createTicket(
            'revoked',
            $suffix,
            $this->buyerId,
            $this->orderId,
            'revoked',
            bin2hex(random_bytes(32))
        );

        $this->createTicket(
            'pending',
            $suffix,
            $this->buyerId,
            $this->pendingOrderId,
            'valid',
            bin2hex(random_bytes(32))
        );
    }

    /**
     * Crear un boleto para las pruebas.
     */
    private function createTicket(
        string $name,
        string $suffix,
        string $userId,
        string $orderId,
        string $status,
        string $token
    ): void {
        $ticketId = 'boleto-impresion-' . $name . '-' . $suffix;

        $this->ticketIds[$name] = $ticketId;

        $this->firestore
            ->collection('tickets')
            ->document($ticketId)
            ->set([
                'orderId' => $orderId,
                'eventId' => $this->eventId,
                'ticketTypeId' => 'general',
                'userId' => $userId,
                'position' => 1,
                'status' => $status,
                'qrTokenHash' => hash('sha256', $token),
                'qrTokenEncrypted' => Crypt::encryptString(
                    $token
                ),
                'issuedAt' => new Timestamp(
                    new DateTimeImmutable()
                ),
                'usedAt' => null,
            ]);
    }

    /**
     * Preparar una solicitud autenticada de prueba.
     */
    private function request(
        ?string $buyerId = null,
        ?string $role = 'customer',
        array $query = []
    ): Request {
        $request = Request::create(
            '/api/tickets/print',
            'GET',
            $query
        );

        if ($buyerId !== null) {
            $request->attributes->set(
                'firebase_uid',
                $buyerId
            );
        }

        if ($role !== null) {
            $request->attributes->set(
                'firebase_role',
                $role
            );
        }

        return $request;
    }

    /**
     * Ejecutar el controlador con el boleto solicitado.
     */
    private function printTicket(
        string $name,
        ?string $buyerId = null,
        ?string $role = 'customer',
        array $query = []
    ): \Illuminate\Http\Response {
        return $this->controller->show(
            $this->request(
                $buyerId ?? $this->buyerId,
                $role,
                $query
            ),
            $this->ticketIds[$name],
            $this->firestore,
            $this->qrService
        );
    }

    public function test_customer_can_print_paid_ticket(): void
    {
        $response = $this->printTicket('valid');

        $this->assertSame(200, $response->getStatusCode());

        $html = $response->getContent();

        $this->assertStringContainsString(
            'Concierto BluePass',
            $html
        );

        $this->assertStringContainsString(
            'Auditorio Principal',
            $html
        );

        $this->assertStringContainsString(
            'Entrada General',
            $html
        );

        $this->assertStringContainsString(
            'Comprador BluePass',
            $html
        );

        $this->assertStringContainsString(
            $this->ticketIds['valid'],
            $html
        );

        $this->assertStringContainsString(
            'Imprimir o guardar en PDF',
            $html
        );
    }

    public function test_printed_ticket_contains_qr_image(): void
    {
        $response = $this->printTicket('valid');

        $this->assertSame(200, $response->getStatusCode());

        $html = $response->getContent();

        $this->assertStringContainsString(
            'data:image/svg+xml;base64,',
            $html
        );

        $this->assertStringNotContainsString(
            $this->qrToken,
            $html
        );

        $this->assertStringNotContainsString(
            'qrTokenEncrypted',
            $html
        );

        $this->assertStringNotContainsString(
            'qrTokenHash',
            $html
        );
    }

    public function test_customer_cannot_print_another_ticket(): void
    {
        $response = $this->printTicket('other');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_used_ticket_cannot_be_printed(): void
    {
        $response = $this->printTicket('used');

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_revoked_ticket_cannot_be_printed(): void
    {
        $response = $this->printTicket('revoked');

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_pending_order_cannot_print_ticket(): void
    {
        $response = $this->printTicket('pending');

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_print_requires_identity(): void
    {
        $response = $this->controller->show(
            $this->request(null, null),
            $this->ticketIds['valid'],
            $this->firestore,
            $this->qrService
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_seller_cannot_print_customer_ticket(): void
    {
        $response = $this->printTicket(
            'valid',
            $this->buyerId,
            'seller'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_print_rejects_invalid_ticket_id(): void
    {
        $response = $this->controller->show(
            $this->request($this->buyerId),
            'boleto-invalido!',
            $this->firestore,
            $this->qrService
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_print_rejects_unexpected_parameters(): void
    {
        $response = $this->printTicket(
            'valid',
            $this->buyerId,
            'customer',
            ['userId' => $this->otherBuyerId]
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_print_disables_cache(): void
    {
        $response = $this->printTicket('valid');

        $this->assertSame(200, $response->getStatusCode());

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', '')
        );

        $this->assertSame(
            'nosniff',
            $response->headers->get(
                'X-Content-Type-Options'
            )
        );

        $this->assertSame(
            'DENY',
            $response->headers->get('X-Frame-Options')
        );

        $this->assertSame(
            'no-referrer',
            $response->headers->get('Referrer-Policy')
        );
    }

    public function test_order_without_payment_date_cannot_print(): void
    {
        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->update([
                [
                    'path' => 'paidAt',
                    'value' => null,
                ],
            ]);

        $response = $this->printTicket('valid');

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_missing_ticket_returns_404(): void
    {
        $response = $this->controller->show(
            $this->request($this->buyerId),
            'boleto-inexistente-' . bin2hex(random_bytes(8)),
            $this->firestore,
            $this->qrService
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Eliminar los documentos creados durante las pruebas.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                foreach ($this->ticketIds as $ticketId) {
                    $this->firestore
                        ->collection('tickets')
                        ->document($ticketId)
                        ->delete();
                }

                if (isset($this->orderId)) {
                    $this->firestore
                        ->collection('orders')
                        ->document($this->orderId)
                        ->delete();
                }

                if (isset($this->pendingOrderId)) {
                    $this->firestore
                        ->collection('orders')
                        ->document($this->pendingOrderId)
                        ->delete();
                }

                if (isset($this->eventId)) {
                    $event = $this->firestore
                        ->collection('events')
                        ->document($this->eventId);

                    $event
                        ->collection('ticketTypes')
                        ->document('general')
                        ->delete();

                    $event->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
