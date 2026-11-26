<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketQueryController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class TicketQueryControllerTest extends TestCase
{
    private FirestoreClient $firestore;

    private TicketQueryController $controller;

    private string $buyerId;

    private string $otherBuyerId;

    private string $ticketId;

    private string $otherTicketId;

    private string $usedTicketId;

    private string $qrToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutar exclusivamente con Firestore Emulator.
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

        $this->controller = new TicketQueryController();

        $suffix = bin2hex(random_bytes(8));

        $this->buyerId = 'comprador-boletos-' . $suffix;
        $this->otherBuyerId = 'otro-comprador-' . $suffix;

        $this->ticketId = 'test-ticket-own-' . $suffix;
        $this->otherTicketId = 'test-ticket-other-' . $suffix;
        $this->usedTicketId = 'test-ticket-used-' . $suffix;

        $this->qrToken = bin2hex(random_bytes(32));

        $now = new Timestamp(new DateTimeImmutable());

        // Boleto vigente del comprador.
        $this->firestore
            ->collection('tickets')
            ->document($this->ticketId)
            ->set([
                'orderId' => 'order-own-' . $suffix,
                'eventId' => 'event-' . $suffix,
                'ticketTypeId' => 'general',
                'userId' => $this->buyerId,
                'position' => 1,
                'status' => 'valid',
                'qrTokenHash' => hash('sha256', $this->qrToken),
                'qrTokenEncrypted' => Crypt::encryptString(
                    $this->qrToken
                ),
                'issuedAt' => $now,
                'usedAt' => null,
            ]);

        // Boleto que pertenece a otra persona.
        $otherToken = bin2hex(random_bytes(32));

        $this->firestore
            ->collection('tickets')
            ->document($this->otherTicketId)
            ->set([
                'orderId' => 'order-other-' . $suffix,
                'eventId' => 'event-' . $suffix,
                'ticketTypeId' => 'vip',
                'userId' => $this->otherBuyerId,
                'position' => 1,
                'status' => 'valid',
                'qrTokenHash' => hash('sha256', $otherToken),
                'qrTokenEncrypted' => Crypt::encryptString(
                    $otherToken
                ),
                'issuedAt' => $now,
                'usedAt' => null,
            ]);

        // Boleto del comprador que ya fue utilizado.
        $usedToken = bin2hex(random_bytes(32));

        $this->firestore
            ->collection('tickets')
            ->document($this->usedTicketId)
            ->set([
                'orderId' => 'order-used-' . $suffix,
                'eventId' => 'event-' . $suffix,
                'ticketTypeId' => 'general',
                'userId' => $this->buyerId,
                'position' => 1,
                'status' => 'used',
                'qrTokenHash' => hash('sha256', $usedToken),
                'qrTokenEncrypted' => Crypt::encryptString(
                    $usedToken
                ),
                'issuedAt' => $now,
                'usedAt' => $now,
            ]);
    }

    /**
     * Preparar una solicitud con identidad autenticada.
     */
    private function customerRequest(
        ?string $userId = null,
        ?string $role = 'customer',
        array $query = []
    ): Request {
        $request = Request::create(
            '/api/tickets',
            'GET',
            $query
        );

        if ($userId !== null) {
            $request->attributes->set(
                'firebase_uid',
                $userId
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
     * Obtener la lista de boletos del comprador.
     */
    public function test_customer_lists_only_own_tickets(): void
    {
        $response = $this->controller->index(
            $this->customerRequest($this->buyerId),
            $this->firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(2, $body['count']);
        $this->assertCount(2, $body['tickets']);

        $ticketIds = array_column(
            $body['tickets'],
            'id'
        );

        $this->assertContains($this->ticketId, $ticketIds);
        $this->assertContains($this->usedTicketId, $ticketIds);
        $this->assertNotContains(
            $this->otherTicketId,
            $ticketIds
        );
    }

    /**
     * No incluir secretos del QR en el listado.
     */
    public function test_list_does_not_expose_qr_secrets(): void
    {
        $response = $this->controller->index(
            $this->customerRequest($this->buyerId),
            $this->firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        foreach ($body['tickets'] as $ticket) {
            $this->assertArrayNotHasKey(
                'qrTokenEncrypted',
                $ticket
            );

            $this->assertArrayNotHasKey(
                'qrTokenHash',
                $ticket
            );

            $this->assertArrayNotHasKey(
                'qrValue',
                $ticket
            );

            $this->assertArrayNotHasKey(
                'userId',
                $ticket
            );
        }
    }

    /**
     * Un comprador puede consultar su propio boleto.
     */
    public function test_customer_can_view_own_ticket(): void
    {
        $response = $this->controller->show(
            $this->customerRequest($this->buyerId),
            $this->ticketId,
            $this->firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(
            $this->ticketId,
            $body['ticket']['id']
        );

        $this->assertSame(
            'valid',
            $body['ticket']['status']
        );

        $this->assertNotNull(
            $body['ticket']['issuedAt']
        );

        $this->assertArrayNotHasKey(
            'qrTokenEncrypted',
            $body['ticket']
        );
    }

    /**
     * No revelar boletos de otros compradores.
     */
    public function test_customer_cannot_view_another_ticket(): void
    {
        $response = $this->controller->show(
            $this->customerRequest($this->buyerId),
            $this->otherTicketId,
            $this->firestore
        );

        $this->assertSame(404, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertArrayNotHasKey('ticket', $body);
    }

    /**
     * Los identificadores invalidos no deben aceptarse.
     */
    public function test_rejects_invalid_ticket_id(): void
    {
        $response = $this->controller->show(
            $this->customerRequest($this->buyerId),
            'ticket-invalido!',
            $this->firestore
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Un comprador puede obtener la credencial de su QR.
     */
    public function test_customer_can_get_own_qr(): void
    {
        $response = $this->controller->qr(
            $this->customerRequest($this->buyerId),
            $this->ticketId,
            $this->firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(
            $this->ticketId,
            $body['ticketId']
        );

        $this->assertSame(
            $this->qrToken,
            $body['qrValue']
        );

        $this->assertSame('valid', $body['status']);

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
    }

    /**
     * No entregar el QR de otra persona.
     */
    public function test_customer_cannot_get_another_qr(): void
    {
        $response = $this->controller->qr(
            $this->customerRequest($this->buyerId),
            $this->otherTicketId,
            $this->firestore
        );

        $this->assertSame(404, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertArrayNotHasKey('qrValue', $body);

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', '')
        );
    }

    /**
     * No entregar QR de boletos utilizados.
     */
    public function test_used_ticket_cannot_get_qr(): void
    {
        $response = $this->controller->qr(
            $this->customerRequest($this->buyerId),
            $this->usedTicketId,
            $this->firestore
        );

        $this->assertSame(409, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertArrayNotHasKey('qrValue', $body);
    }

    /**
     * Rechazar consultas sin una sesion autenticada.
     */
    public function test_tickets_require_identity(): void
    {
        $request = $this->customerRequest(null, null);

        $list = $this->controller->index(
            $request,
            $this->firestore
        );

        $detail = $this->controller->show(
            $request,
            $this->ticketId,
            $this->firestore
        );

        $qr = $this->controller->qr(
            $request,
            $this->ticketId,
            $this->firestore
        );

        $this->assertSame(401, $list->getStatusCode());
        $this->assertSame(401, $detail->getStatusCode());
        $this->assertSame(401, $qr->getStatusCode());
    }

    /**
     * No permitir consultas con el rol vendedor.
     */
    public function test_seller_cannot_access_customer_tickets(): void
    {
        $request = $this->customerRequest(
            $this->buyerId,
            'seller'
        );

        $list = $this->controller->index(
            $request,
            $this->firestore
        );

        $detail = $this->controller->show(
            $request,
            $this->ticketId,
            $this->firestore
        );

        $qr = $this->controller->qr(
            $request,
            $this->ticketId,
            $this->firestore
        );

        $this->assertSame(403, $list->getStatusCode());
        $this->assertSame(403, $detail->getStatusCode());
        $this->assertSame(403, $qr->getStatusCode());
    }

    /**
     * Rechazar parametros de consulta no permitidos.
     */
    public function test_list_rejects_unexpected_parameters(): void
    {
        $response = $this->controller->index(
            $this->customerRequest(
                $this->buyerId,
                'customer',
                ['userId' => $this->otherBuyerId]
            ),
            $this->firestore
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * Un usuario sin boletos obtiene una lista vacia.
     */
    public function test_customer_without_tickets_gets_empty_list(): void
    {
        $response = $this->controller->index(
            $this->customerRequest(
                'comprador-sin-boletos-' .
                    bin2hex(random_bytes(8))
            ),
            $this->firestore
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(0, $body['count']);
        $this->assertSame([], $body['tickets']);
    }

    /**
     * Eliminar los documentos creados por las pruebas.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                foreach (
                    [
                        $this->ticketId ?? null,
                        $this->otherTicketId ?? null,
                        $this->usedTicketId ?? null,
                    ] as $ticketId
                ) {
                    if ($ticketId === null) {
                        continue;
                    }

                    $this->firestore
                        ->collection('tickets')
                        ->document($ticketId)
                        ->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
