<?php

namespace Tests\Feature;

use App\Services\TicketIdGenerator;
use App\Services\TicketIssuanceService;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

class MultiTicketPurchaseConfirmationIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $eventDocument;

    private string $eventId;
    private string $orderId;
    private string $reservationId;
    private string $buyerId;
    private string $paymentReference;

    protected function setUp(): void
    {
        parent::setUp();

        // Estas pruebas solo utilizan Firestore Emulator.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'Estas pruebas requieren Firestore Emulator.'
            );
        }

        Mail::fake();

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        // Usar la misma conexion en todos los servicios.
        $this->app->instance(
            FirestoreClient::class,
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'test-multi-confirm-' . $suffix;
        $this->orderId = 'test-multi-order-' . $suffix;
        $this->reservationId = 'test-multi-res-' . $suffix;
        $this->buyerId = 'test-multi-buyer-' . $suffix;
        $this->paymentReference = 'multi-payment-' . $suffix;

        $now = new DateTimeImmutable();
        $timestamp = new Timestamp($now);

        $this->eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $this->eventDocument->set([
            'name' => 'Evento de confirmacion multiple',
            'status' => 'published',
            'createdAt' => $timestamp,
        ]);

        foreach ([
            'general' => [
                'price' => 50000,
                'quantity' => 3,
            ],
            'vip' => [
                'price' => 150000,
                'quantity' => 2,
            ],
        ] as $typeId => $data) {
            $this->eventDocument
                ->collection('ticketTypes')
                ->document($typeId)
                ->set([
                    'status' => 'active',
                    'capacity' => 20,
                    'sold' => 0,
                    'reserved' => $data['quantity'],
                    'price' => $data['price'],
                    'currency' => 'MXN',
                    'updatedAt' => $timestamp,
                ]);
        }

        $reservationItems = [
            [
                'ticketTypeId' => 'general',
                'quantity' => 3,
                'unitPrice' => 50000,
                'currency' => 'MXN',
            ],
            [
                'ticketTypeId' => 'vip',
                'quantity' => 2,
                'unitPrice' => 150000,
                'currency' => 'MXN',
            ],
        ];

        $orderItems = [
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
        ];

        $this->eventDocument
            ->collection('reservations')
            ->document($this->reservationId)
            ->set([
                'orderId' => $this->orderId,
                'userId' => $this->buyerId,
                'eventId' => $this->eventId,
                'quantity' => 5,
                'currency' => 'MXN',
                'items' => $reservationItems,
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
                'reservationId' => $this->reservationId,
                'quantity' => 5,
                'currency' => 'MXN',
                'items' => $orderItems,
                'subtotal' => 450000,
                'fees' => 0,
                'total' => 450000,
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
     * Obtener el registro de notificacion.
     */
    private function notificationDocument(): DocumentReference
    {
        return $this->firestore
            ->collection('notifications')
            ->document(
                hash(
                    'sha256',
                    'purchase_confirmed:' . $this->orderId
                )
            );
    }

    /**
     * Obtener el registro de correo pendiente.
     */
    private function emailDocument(): DocumentReference
    {
        return $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document(
                hash(
                    'sha256',
                    'purchase_confirmation:' . $this->orderId
                )
            );
    }

    /**
     * Obtener la referencia de pago.
     */
    private function paymentDocument(): DocumentReference
    {
        return $this->firestore
            ->collection('paymentReferences')
            ->document(
                hash('sha256', $this->paymentReference)
            );
    }

    /**
     * Consultar un inventario.
     */
    private function inventory(string $typeId): array
    {
        return $this->eventDocument
            ->collection('ticketTypes')
            ->document($typeId)
            ->snapshot()
            ->data();
    }

    /**
     * Consultar el pedido.
     */
    private function order(): array
    {
        return $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->snapshot()
            ->data();
    }

    /**
     * Contar registros vinculados con el pedido.
     */
    private function countRecords(string $collection): int
    {
        $documents = $this->firestore
            ->collection($collection)
            ->where('orderId', '=', $this->orderId)
            ->documents();

        $count = 0;

        foreach ($documents as $document) {
            if ($document->exists()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Simular una confirmacion interna en el emulador.
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
     * Una compra multiple genera una notificacion
     * y un correo pendiente para sus cinco boletos.
     */
    public function test_multiple_purchase_creates_notification_and_email(): void
    {
        $result = $this->issue();

        $this->assertSame('paid', $result['status']);
        $this->assertFalse($result['alreadyIssued']);
        $this->assertCount(5, $result['ticketIds']);

        $notificationSnapshot = $this->notificationDocument()
            ->snapshot();

        $emailSnapshot = $this->emailDocument()
            ->snapshot();

        $this->assertTrue($notificationSnapshot->exists());
        $this->assertTrue($emailSnapshot->exists());

        $notification = $notificationSnapshot->data();
        $email = $emailSnapshot->data();

        $this->assertSame(
            $this->orderId,
            $notification['orderId']
        );

        $this->assertSame(
            $this->buyerId,
            $notification['userId']
        );

        $this->assertSame(
            'purchase_confirmed',
            $notification['type']
        );

        $this->assertSame(
            'unread',
            $notification['status']
        );

        $this->assertSame(
            5,
            $notification['ticketCount']
        );

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

        $this->assertSame(
            'pending',
            $email['status']
        );

        $this->assertSame(5, $email['ticketCount']);
        $this->assertSame(0, $email['attempts']);

        $this->assertSame(
            1,
            $this->countRecords('notifications')
        );

        $this->assertSame(
            1,
            $this->countRecords('purchaseEmailOutbox')
        );

        $this->assertSame(
            'paid',
            $this->order()['status']
        );

        $this->assertSame(
            $result['ticketIds'],
            $this->order()['issuedTicketIds']
        );

        $this->assertSame(
            3,
            $this->inventory('general')['sold']
        );

        $this->assertSame(
            2,
            $this->inventory('vip')['sold']
        );

        Mail::assertNothingSent();
    }

    /**
     * Repetir la confirmacion no duplica registros.
     */
    public function test_repeated_confirmation_preserves_records(): void
    {
        $first = $this->issue();

        $notificationBefore = $this->notificationDocument()
            ->snapshot()
            ->data();

        $emailBefore = $this->emailDocument()
            ->snapshot()
            ->data();

        $second = $this->issue();

        $notificationAfter = $this->notificationDocument()
            ->snapshot()
            ->data();

        $emailAfter = $this->emailDocument()
            ->snapshot()
            ->data();

        $this->assertFalse($first['alreadyIssued']);
        $this->assertTrue($second['alreadyIssued']);

        $this->assertSame(
            $first['ticketIds'],
            $second['ticketIds']
        );

        $this->assertSame(
            $notificationBefore['createdAt']
                ->get()->format('U.u'),
            $notificationAfter['createdAt']
                ->get()->format('U.u')
        );

        $this->assertSame(
            $emailBefore['createdAt']
                ->get()->format('U.u'),
            $emailAfter['createdAt']
                ->get()->format('U.u')
        );

        $this->assertSame(
            1,
            $this->countRecords('notifications')
        );

        $this->assertSame(
            1,
            $this->countRecords('purchaseEmailOutbox')
        );

        $this->assertSame(
            3,
            $this->inventory('general')['sold']
        );

        $this->assertSame(
            2,
            $this->inventory('vip')['sold']
        );

        $this->assertSame(
            0,
            $this->inventory('general')['reserved']
        );

        $this->assertSame(
            0,
            $this->inventory('vip')['reserved']
        );

        Mail::assertNothingSent();
    }

    /**
     * Una compra rechazada no genera registros.
     */
    public function test_rejected_purchase_creates_no_records(): void
    {
        // Simular inventario VIP insuficiente.
        $this->eventDocument
            ->collection('ticketTypes')
            ->document('vip')
            ->update([
                [
                    'path' => 'reserved',
                    'value' => 1,
                ],
            ]);

        $this->expectException(
            InvalidArgumentException::class
        );

        try {
            $this->issue();
        } finally {
            $this->assertFalse(
                $this->notificationDocument()
                    ->snapshot()
                    ->exists()
            );

            $this->assertFalse(
                $this->emailDocument()
                    ->snapshot()
                    ->exists()
            );

            $this->assertFalse(
                $this->paymentDocument()
                    ->snapshot()
                    ->exists()
            );

            $this->assertSame(
                'pending',
                $this->order()['status']
            );

            $this->assertSame(
                0,
                $this->inventory('general')['sold']
            );

            $this->assertSame(
                3,
                $this->inventory('general')['reserved']
            );

            $this->assertSame(
                0,
                $this->inventory('vip')['sold']
            );

            Mail::assertNothingSent();
        }
    }

    /**
     * Eliminar los documentos creados en el emulador.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore, $this->orderId)) {
                $ticketIds = app(TicketIdGenerator::class)
                    ->generateForOrder($this->orderId, 5);

                foreach ($ticketIds as $ticketId) {
                    $this->firestore
                        ->collection('tickets')
                        ->document($ticketId)
                        ->delete();
                }

                $this->notificationDocument()->delete();
                $this->emailDocument()->delete();
                $this->paymentDocument()->delete();

                $this->firestore
                    ->collection('orders')
                    ->document($this->orderId)
                    ->delete();
            }

            if (isset($this->eventDocument)) {
                if (isset($this->reservationId)) {
                    $this->eventDocument
                        ->collection('reservations')
                        ->document($this->reservationId)
                        ->delete();
                }

                foreach (['general', 'vip'] as $typeId) {
                    $this->eventDocument
                        ->collection('ticketTypes')
                        ->document($typeId)
                        ->delete();
                }

                $this->eventDocument->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
