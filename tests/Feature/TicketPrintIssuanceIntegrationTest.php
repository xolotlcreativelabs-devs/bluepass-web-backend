<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketPrintController;
use App\Services\TicketIdGenerator;
use App\Services\TicketIssuanceService;
use App\Services\TicketQrImageService;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class TicketPrintIssuanceIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $buyerId;
    private string $eventId;
    private string $orderId;
    private string $reservationId;
    private string $paymentReference;

    protected function setUp(): void
    {
        parent::setUp();

        // Estas pruebas solo pueden utilizar Firestore Emulator.
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

        $this->buyerId = 'comprador-print-real-' . $suffix;
        $this->eventId = 'evento-print-real-' . $suffix;
        $this->orderId = 'pedido-print-real-' . $suffix;
        $this->reservationId = 'reserva-print-real-' . $suffix;
        $this->paymentReference = 'pago-print-real-' . $suffix;

        $now = new DateTimeImmutable();
        $timestamp = new Timestamp($now);

        $event = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $event->set([
            'sellerId' => 'vendedor-prueba',
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
            'createdAt' => $timestamp,
        ]);

        $event
            ->collection('ticketTypes')
            ->document('general')
            ->set([
                'name' => 'Entrada General',
                'capacity' => 100,
                'sold' => 0,
                'reserved' => 2,
                'status' => 'active',
                'updatedAt' => $timestamp,
            ]);

        $event
            ->collection('reservations')
            ->document($this->reservationId)
            ->set([
                'orderId' => $this->orderId,
                'userId' => $this->buyerId,
                'eventId' => $this->eventId,
                'ticketTypeId' => 'general',
                'quantity' => 2,
                'status' => 'active',
                'expiresAt' => new Timestamp(
                    $now->modify('+15 minutes')
                ),
                'createdAt' => $timestamp,
            ]);

        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->set([
                'userId' => $this->buyerId,
                'eventId' => $this->eventId,
                'ticketTypeId' => 'general',
                'reservationId' => $this->reservationId,
                'quantity' => 2,
                'status' => 'pending',
                'buyer' => [
                    'name' => 'Comprador de prueba',
                    'email' => 'comprador@example.com',
                ],
                'createdAt' => $timestamp,
            ]);
    }

    /**
     * Preparar la solicitud del comprador.
     */
    private function printRequest(): Request
    {
        $request = Request::create(
            '/api/tickets/print',
            'GET'
        );

        $request->attributes->set(
            'firebase_uid',
            $this->buyerId
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        return $request;
    }

    /**
     * Consultar el boleto imprimible.
     */
    private function printTicket(string $ticketId)
    {
        return (new TicketPrintController())->show(
            $this->printRequest(),
            $ticketId,
            $this->firestore,
            new TicketQrImageService()
        );
    }

    public function test_issued_tickets_can_be_printed(): void
    {
        // El comprador no puede imprimir antes del pago.
        $ticketIds = app(TicketIdGenerator::class)
            ->generateForOrder($this->orderId, 2);

        foreach ($ticketIds as $ticketId) {
            $this->assertSame(
                404,
                $this->printTicket($ticketId)->getStatusCode()
            );
        }

        // Simular una confirmacion recibida de un sistema confiable.
        $result = app(TicketIssuanceService::class)
            ->issueForVerifiedPayment(
                $this->orderId,
                $this->paymentReference
            );

        $this->assertSame('paid', $result['status']);
        $this->assertFalse($result['alreadyIssued']);
        $this->assertCount(2, $result['ticketIds']);

        foreach ($result['ticketIds'] as $ticketId) {
            $response = $this->printTicket($ticketId);

            $this->assertSame(
                200,
                $response->getStatusCode()
            );

            $html = $response->getContent();

            $this->assertStringContainsString(
                'Concierto BluePass',
                $html
            );

            $this->assertStringContainsString(
                'Entrada General',
                $html
            );

            $this->assertStringContainsString(
                $ticketId,
                $html
            );

            $this->assertStringContainsString(
                'data:image/svg+xml;base64,',
                $html
            );

            $ticket = $this->firestore
                ->collection('tickets')
                ->document($ticketId)
                ->snapshot()
                ->data();

            $this->assertSame('valid', $ticket['status']);

            $token = Crypt::decryptString(
                $ticket['qrTokenEncrypted']
            );

            $this->assertSame(
                hash('sha256', $token),
                $ticket['qrTokenHash']
            );

            $this->assertStringNotContainsString(
                $token,
                $html
            );
        }

        $order = $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->snapshot()
            ->data();

        $this->assertSame('paid', $order['status']);
        $this->assertNotNull($order['paidAt']);
    }

    /**
     * Eliminar solamente los datos de esta prueba.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore, $this->orderId)) {
                $ticketIds = app(TicketIdGenerator::class)
                    ->generateForOrder($this->orderId, 2);

                foreach ($ticketIds as $ticketId) {
                    $this->firestore
                        ->collection('tickets')
                        ->document($ticketId)
                        ->delete();
                }

                $this->firestore
                    ->collection('paymentReferences')
                    ->document(
                        hash('sha256', $this->paymentReference)
                    )
                    ->delete();

                $this->firestore
                    ->collection('orders')
                    ->document($this->orderId)
                    ->delete();

                $event = $this->firestore
                    ->collection('events')
                    ->document($this->eventId);

                $event
                    ->collection('reservations')
                    ->document($this->reservationId)
                    ->delete();

                $event
                    ->collection('ticketTypes')
                    ->document('general')
                    ->delete();

                $event->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
