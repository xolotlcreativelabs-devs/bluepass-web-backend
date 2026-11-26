<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderQueryController;
use App\Http\Controllers\Api\OrderReceiptController;
use App\Http\Controllers\Api\TicketQueryController;
use App\Services\TicketIdGenerator;
use App\Services\TicketQrToken;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class MultiTicketBuyerContractIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $eventId;
    private string $orderId;
    private string $buyerId;

    private array $ticketIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca ejecutar estas pruebas con Firebase real.
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

        $this->app->instance(
            FirestoreClient::class,
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'test-buyer-contract-' . $suffix;
        $this->orderId = 'test-contract-order-' . $suffix;
        $this->buyerId = 'test-contract-buyer-' . $suffix;

        $now = new DateTimeImmutable();
        $timestamp = new Timestamp($now);

        $event = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $event->set([
            'name' => 'Concierto BluePass',
            'venue' => 'Auditorio de prueba',
            'timezone' => 'America/Mexico_City',
            'status' => 'published',
            'date' => new Timestamp(
                $now->modify('+10 days')
            ),
            'endAt' => new Timestamp(
                $now->modify('+10 days +3 hours')
            ),
            'createdAt' => $timestamp,
        ]);

        // Preparar un pedido mixto ya confirmado.
        // Los datos son exclusivamente del emulador.
        $this->ticketIds = app(TicketIdGenerator::class)
            ->generateForOrder($this->orderId, 5);

        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->set([
                'userId' => $this->buyerId,
                'eventId' => $this->eventId,
                'sellerId' => 'vendedor-prueba',
                'channel' => 'web',
                'reservationId' => 'reserva-prueba',
                'quantity' => 5,
                'items' => [
                    [
                        'ticketTypeId' => 'general',
                        'quantity' => 3,
                        'unitPrice' => 50000,
                        'subtotal' => 150000,
                    ],
                    [
                        'ticketTypeId' => 'vip',
                        'quantity' => 2,
                        'unitPrice' => 150000,
                        'subtotal' => 300000,
                    ],
                ],
                'currency' => 'MXN',
                'subtotal' => 450000,
                'fees' => 0,
                'total' => 450000,
                'status' => 'paid',
                'buyer' => [
                    'id' => $this->buyerId,
                    'name' => 'Comprador de prueba',
                    'email' => 'comprador@example.com',
                ],
                'issuedTicketIds' => $this->ticketIds,
                'createdAt' => $timestamp,
                'paidAt' => $timestamp,
                'updatedAt' => $timestamp,
            ]);

        // Crear tres entradas Generales y dos VIP.
        foreach ($this->ticketIds as $index => $ticketId) {
            $typeId = $index < 3
                ? 'general'
                : 'vip';

            $token = app(TicketQrToken::class)
                ->generate();

            $this->firestore
                ->collection('tickets')
                ->document($ticketId)
                ->set([
                    'orderId' => $this->orderId,
                    'eventId' => $this->eventId,
                    'ticketTypeId' => $typeId,
                    'userId' => $this->buyerId,
                    'position' => $index + 1,
                    'status' => 'valid',
                    'qrTokenHash' => app(
                        TicketQrToken::class
                    )->hash($token),
                    'qrTokenEncrypted' =>
                        Crypt::encryptString($token),
                    'issuedAt' => $timestamp,
                    'usedAt' => null,
                    'updatedAt' => $timestamp,
                ]);
        }
    }

    /**
     * Preparar una solicitud de un comprador.
     */
    private function request(
        ?string $buyerId = null
    ): Request {
        $request = Request::create(
            '/api/test',
            'GET'
        );

        $request->attributes->set(
            'firebase_uid',
            $buyerId ?? $this->buyerId
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        return $request;
    }

    /**
     * Comprobar las categorias de un pedido.
     */
    private function assertOrderItems(array $items): void
    {
        $this->assertCount(2, $items);

        $byType = [];

        foreach ($items as $item) {
            $byType[$item['ticketTypeId']] = $item;
        }

        $this->assertArrayHasKey('general', $byType);
        $this->assertArrayHasKey('vip', $byType);

        $this->assertSame(
            3,
            $byType['general']['quantity']
        );

        $this->assertSame(
            50000,
            $byType['general']['unitPrice']
        );

        $this->assertSame(
            150000,
            $byType['general']['subtotal']
        );

        $this->assertSame(
            2,
            $byType['vip']['quantity']
        );

        $this->assertSame(
            150000,
            $byType['vip']['unitPrice']
        );

        $this->assertSame(
            300000,
            $byType['vip']['subtotal']
        );
    }

    /**
     * El historial y detalle mantienen los importes.
     */
    public function test_history_and_detail_show_multiple_categories(): void
    {
        $controller = new OrderQueryController();

        $listResponse = $controller->index(
            $this->request(),
            $this->firestore
        );

        $this->assertSame(
            200,
            $listResponse->getStatusCode(),
            $listResponse->getContent()
        );

        $list = $listResponse->getData(true);

        $this->assertSame(1, $list['count']);
        $this->assertCount(1, $list['orders']);

        $summary = $list['orders'][0];

        $this->assertSame(
            $this->orderId,
            $summary['id']
        );

        $this->assertSame(5, $summary['quantity']);
        $this->assertSame('paid', $summary['status']);
        $this->assertSame('MXN', $summary['currency']);

        $this->assertSame(
            450000,
            $summary['total']
        );

        $this->assertOrderItems(
            $summary['items']
        );

        $detailResponse = $controller->show(
            $this->request(),
            $this->orderId,
            $this->firestore
        );

        $this->assertSame(
            200,
            $detailResponse->getStatusCode(),
            $detailResponse->getContent()
        );

        $detail = $detailResponse->getData(true);

        $this->assertSame(
            $this->orderId,
            $detail['order']['id']
        );

        $this->assertSame(
            $summary['items'],
            $detail['order']['items']
        );

        $this->assertSame(
            $summary['total'],
            $detail['order']['total']
        );

        $this->assertSame(
            'upcoming',
            $detail['history']['group']
        );
    }

    /**
     * Mis boletos devuelve cinco entradas individuales.
     */
    public function test_tickets_keep_their_categories_and_qr(): void
    {
        $controller = new TicketQueryController();

        $response = $controller->index(
            $this->request(),
            $this->firestore
        );

        $this->assertSame(
            200,
            $response->getStatusCode(),
            $response->getContent()
        );

        $data = $response->getData(true);

        $this->assertSame(5, $data['count']);

        $categories = [
            'general' => 0,
            'vip' => 0,
        ];

        foreach ($data['tickets'] as $ticket) {
            $this->assertSame(
                $this->orderId,
                $ticket['orderId']
            );

            $this->assertSame(
                'valid',
                $ticket['status']
            );

            $this->assertArrayHasKey(
                $ticket['ticketTypeId'],
                $categories
            );

            $categories[$ticket['ticketTypeId']]++;

            // Los secretos QR no deben aparecer
            // dentro de la lista de boletos.
            $this->assertArrayNotHasKey(
                'qrTokenEncrypted',
                $ticket
            );

            $this->assertArrayNotHasKey(
                'qrTokenHash',
                $ticket
            );
        }

        $this->assertSame([
            'general' => 3,
            'vip' => 2,
        ], $categories);

        // Comprobar que el propietario puede
        // consultar la credencial de su boleto.
        $qrResponse = $controller->qr(
            $this->request(),
            $this->ticketIds[0],
            $this->firestore
        );

        $this->assertSame(
            200,
            $qrResponse->getStatusCode(),
            $qrResponse->getContent()
        );

        $qr = $qrResponse->getData(true);

        $this->assertSame(
            $this->ticketIds[0],
            $qr['ticketId']
        );

        $this->assertNotEmpty(
            $qr['qrValue']
        );

        // Otro comprador no debe obtener ese QR.
        $otherResponse = $controller->qr(
            $this->request('otro-comprador'),
            $this->ticketIds[0],
            $this->firestore
        );

        $this->assertSame(
            404,
            $otherResponse->getStatusCode()
        );
    }

    /**
     * El comprobante conserva las dos categorias.
     */
    public function test_receipt_shows_correct_multiple_amounts(): void
    {
        $controller = new OrderReceiptController();

        $response = $controller->show(
            $this->request(),
            $this->orderId,
            $this->firestore
        );

        $this->assertSame(
            200,
            $response->getStatusCode(),
            $response->getContent()
        );

        $data = $response->getData(true);

        $receipt = $data['receipt'];

        $this->assertSame(
            $this->orderId,
            $receipt['orderId']
        );

        $this->assertSame(5, $receipt['quantity']);
        $this->assertSame('MXN', $receipt['currency']);

        $this->assertSame(
            450000,
            $receipt['subtotal']
        );

        $this->assertSame(0, $receipt['fees']);

        $this->assertSame(
            450000,
            $receipt['total']
        );

        $this->assertSame(
            'paid',
            $receipt['status']
        );

        $this->assertNotEmpty(
            $receipt['paidAt']
        );

        $this->assertOrderItems(
            $receipt['items']
        );

        // No permitir consultar el comprobante
        // de otra persona.
        $otherResponse = $controller->show(
            $this->request('otro-comprador'),
            $this->orderId,
            $this->firestore
        );

        $this->assertSame(
            404,
            $otherResponse->getStatusCode()
        );
    }

    /**
     * Eliminar los datos temporales del emulador.
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

                if (isset($this->eventId)) {
                    $this->firestore
                        ->collection('events')
                        ->document($this->eventId)
                        ->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
