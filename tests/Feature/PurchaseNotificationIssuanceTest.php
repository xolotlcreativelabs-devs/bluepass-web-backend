<?php

namespace Tests\Feature;

use App\Services\TicketIdGenerator;
use App\Services\TicketIssuanceService;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use RuntimeException;
use Tests\TestCase;

class PurchaseNotificationIssuanceTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $buyerId;
    private string $eventId;
    private string $orderId;
    private string $reservationId;
    private string $paymentReference;
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

        $suffix = bin2hex(random_bytes(8));

        $this->buyerId = 'buyer-notify-issue-' . $suffix;
        $this->eventId = 'event-notify-issue-' . $suffix;
        $this->orderId = 'order-notify-issue-' . $suffix;
        $this->reservationId = 'res-notify-issue-' . $suffix;
        $this->paymentReference = 'payment-notify-issue-' . $suffix;

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
                'createdAt' => $timestamp,
            ]);
    }

    /**
     * Consultar la notificacion de esta compra.
     */
    private function notification()
    {
        return $this->firestore
            ->collection('notifications')
            ->document($this->notificationId);
    }

    public function test_verified_payment_creates_one_notification(): void
    {
        $result = app(TicketIssuanceService::class)
            ->issueForVerifiedPayment(
                $this->orderId,
                $this->paymentReference
            );

        $this->assertSame('paid', $result['status']);
        $this->assertFalse($result['alreadyIssued']);
        $this->assertCount(2, $result['ticketIds']);

        $snapshot = $this->notification()->snapshot();

        $this->assertTrue($snapshot->exists());

        $data = $snapshot->data();

        $this->assertSame($this->buyerId, $data['userId']);
        $this->assertSame($this->orderId, $data['orderId']);

        $this->assertSame(
            'purchase_confirmed',
            $data['type']
        );

        $this->assertSame('unread', $data['status']);
        $this->assertSame('in_app', $data['channel']);
        $this->assertSame(2, $data['ticketCount']);
        $this->assertNull($data['readAt']);

        $this->assertInstanceOf(
            Timestamp::class,
            $data['createdAt']
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
    }

    public function test_repeated_confirmation_preserves_notification(): void
    {
        $service = app(TicketIssuanceService::class);

        $first = $service->issueForVerifiedPayment(
            $this->orderId,
            $this->paymentReference
        );

        $before = $this->notification()
            ->snapshot()
            ->data();

        $second = $service->issueForVerifiedPayment(
            $this->orderId,
            $this->paymentReference
        );

        $afterSnapshot = $this->notification()->snapshot();

        $this->assertTrue($afterSnapshot->exists());

        $after = $afterSnapshot->data();

        $this->assertFalse($first['alreadyIssued']);
        $this->assertTrue($second['alreadyIssued']);

        $this->assertSame(
            $first['ticketIds'],
            $second['ticketIds']
        );

        $this->assertSame(
            $before['createdAt']->get()->format('U.u'),
            $after['createdAt']->get()->format('U.u')
        );

        $this->assertSame(
            $before['ticketCount'],
            $after['ticketCount']
        );

        $this->assertSame(
            'unread',
            $after['status']
        );

        $matching = $this->firestore
            ->collection('notifications')
            ->where('orderId', '=', $this->orderId)
            ->documents();

        $count = 0;

        foreach ($matching as $document) {
            if ($document->exists()) {
                $count++;
            }
        }

        $this->assertSame(1, $count);
    }

    public function test_rejected_order_creates_no_notification(): void
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

        try {
            app(TicketIssuanceService::class)
                ->issueForVerifiedPayment(
                    $this->orderId,
                    $this->paymentReference
                );

            $this->fail(
                'El pedido cancelado no debe ser confirmado.'
            );
        } catch (RuntimeException $exception) {
            $this->assertNotSame(
                '',
                $exception->getMessage()
            );
        }

        $this->assertFalse(
            $this->notification()->snapshot()->exists()
        );

        $this->assertFalse(
            $this->firestore
                ->collection('paymentReferences')
                ->document(
                    hash('sha256', $this->paymentReference)
                )
                ->snapshot()
                ->exists()
        );

        $order = $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->snapshot()
            ->data();

        $this->assertSame('cancelled', $order['status']);
    }

    /**
     * Eliminar los documentos temporales.
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

                $this->notification()->delete();

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
