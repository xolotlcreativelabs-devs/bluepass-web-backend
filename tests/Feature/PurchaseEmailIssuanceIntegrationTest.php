<?php

namespace Tests\Feature;

use App\Services\TicketIdGenerator;
use App\Services\TicketIssuanceService;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class PurchaseEmailIssuanceIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $buyerId;
    private string $eventId;
    private string $orderId;
    private string $reservationId;
    private string $paymentReference;
    private string $emailId;
    private string $notificationId;

    protected function setUp(): void
    {
        parent::setUp();

        // Utilizar solamente Firestore Emulator.
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

        Mail::fake();

        $suffix = bin2hex(random_bytes(8));

        $this->buyerId = 'buyer-email-issue-' . $suffix;
        $this->eventId = 'event-email-issue-' . $suffix;
        $this->orderId = 'order-email-issue-' . $suffix;
        $this->reservationId = 'res-email-issue-' . $suffix;
        $this->paymentReference = 'payment-email-issue-' . $suffix;

        $this->emailId = hash(
            'sha256',
            'purchase_confirmation:' . $this->orderId
        );

        $this->notificationId = hash(
            'sha256',
            'purchase_confirmed:' . $this->orderId
        );

        $now = new DateTimeImmutable();
        $timestamp = new Timestamp($now);

        $event = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $event->set([
            'status' => 'published',
            'createdAt' => $timestamp,
        ]);

        $event
            ->collection('ticketTypes')
            ->document('general')
            ->set([
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
                    'id' => $this->buyerId,
                    'name' => 'Comprador BluePass',
                    'email' => 'comprador@example.com',
                ],
                'createdAt' => $timestamp,
            ]);
    }

    /**
     * Consultar el registro de correo del pedido.
     */
    private function emailDocument()
    {
        return $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document($this->emailId);
    }

    /**
     * Consultar la notificacion del pedido.
     */
    private function notificationDocument()
    {
        return $this->firestore
            ->collection('notifications')
            ->document($this->notificationId);
    }

    /**
     * Emitir boletos utilizando el servicio real.
     */
    private function issue(): array
    {
        return app(TicketIssuanceService::class)
            ->issueForVerifiedPayment(
                $this->orderId,
                $this->paymentReference
            );
    }

    /**
     * Registrar un correo pendiente para una compra valida.
     */
    public function test_paid_order_creates_pending_email(): void
    {
        $result = $this->issue();

        $this->assertSame('paid', $result['status']);
        $this->assertFalse($result['alreadyIssued']);
        $this->assertCount(2, $result['ticketIds']);

        $snapshot = $this->emailDocument()->snapshot();

        $this->assertTrue($snapshot->exists());

        $email = $snapshot->data();

        $this->assertSame(
            $this->orderId,
            $email['orderId']
        );

        $this->assertSame(
            $this->buyerId,
            $email['userId']
        );

        $this->assertSame(
            'purchase_confirmation',
            $email['type']
        );

        $this->assertSame(
            'Comprador BluePass',
            $email['recipientName']
        );

        $this->assertSame(
            'comprador@example.com',
            $email['recipientEmail']
        );

        $this->assertSame(2, $email['ticketCount']);
        $this->assertSame('pending', $email['status']);
        $this->assertSame(0, $email['attempts']);
        $this->assertNull($email['acceptedAt']);

        $this->assertInstanceOf(
            Timestamp::class,
            $email['createdAt']
        );

        $this->assertTrue(
            $this->notificationDocument()
                ->snapshot()
                ->exists()
        );

        $order = $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->snapshot()
            ->data();

        $this->assertSame('paid', $order['status']);

        $this->assertSame(
            $result['ticketIds'],
            $order['issuedTicketIds']
        );

        Mail::assertNothingSent();
    }

    /**
     * Un correo invalido no debe impedir emitir boletos.
     */
    public function test_invalid_email_does_not_block_ticket_issuance(): void
    {
        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->update([
                [
                    'path' => 'buyer.email',
                    'value' => 'correo-invalido',
                ],
            ]);

        $result = $this->issue();

        $this->assertSame('paid', $result['status']);
        $this->assertFalse($result['alreadyIssued']);
        $this->assertCount(2, $result['ticketIds']);

        $this->assertFalse(
            $this->emailDocument()
                ->snapshot()
                ->exists()
        );

        $this->assertTrue(
            $this->notificationDocument()
                ->snapshot()
                ->exists()
        );

        foreach ($result['ticketIds'] as $ticketId) {
            $ticket = $this->firestore
                ->collection('tickets')
                ->document($ticketId)
                ->snapshot();

            $this->assertTrue($ticket->exists());

            $this->assertSame(
                'valid',
                $ticket->data()['status']
            );
        }

        Mail::assertNothingSent();
    }

    /**
     * Confirmar dos veces no debe duplicar el correo.
     */
    public function test_repeated_confirmation_preserves_email_record(): void
    {
        $first = $this->issue();

        $before = $this->emailDocument()
            ->snapshot()
            ->data();

        $second = $this->issue();

        $afterSnapshot = $this->emailDocument()
            ->snapshot();

        $this->assertTrue($afterSnapshot->exists());

        $after = $afterSnapshot->data();

        $this->assertFalse($first['alreadyIssued']);
        $this->assertTrue($second['alreadyIssued']);

        $this->assertSame(
            $first['ticketIds'],
            $second['ticketIds']
        );

        $this->assertSame(
            'pending',
            $after['status']
        );

        $this->assertSame(0, $after['attempts']);

        $this->assertSame(
            $before['createdAt']->get()->format('U.u'),
            $after['createdAt']->get()->format('U.u')
        );

        $this->assertSame(
            $before['recipientEmail'],
            $after['recipientEmail']
        );

        Mail::assertNothingSent();
    }

    /**
     * Un pedido cancelado no crea boletos ni correo.
     */
    public function test_cancelled_order_creates_no_email(): void
    {
        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->update([
                [
                    'path' => 'status',
                    'value' => 'cancelled',
                ],
            ]);

        $rejected = false;

        try {
            $this->issue();
        } catch (RuntimeException $exception) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'El pedido cancelado debio ser rechazado.'
        );

        $this->assertFalse(
            $this->emailDocument()
                ->snapshot()
                ->exists()
        );

        $this->assertFalse(
            $this->notificationDocument()
                ->snapshot()
                ->exists()
        );

        $paymentDocument = $this->firestore
            ->collection('paymentReferences')
            ->document(
                hash('sha256', $this->paymentReference)
            );

        $this->assertFalse(
            $paymentDocument->snapshot()->exists()
        );

        $order = $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->snapshot()
            ->data();

        $this->assertSame('cancelled', $order['status']);

        Mail::assertNothingSent();
    }

    /**
     * Eliminar los datos temporales del emulador.
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

                $this->emailDocument()->delete();
                $this->notificationDocument()->delete();

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
