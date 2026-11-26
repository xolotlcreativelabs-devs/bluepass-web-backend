<?php

namespace Tests\Feature;

use App\Services\TicketIdGenerator;
use App\Services\TicketIssuanceService;
use App\Services\TicketQrToken;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Tests\TestCase;

class TicketIssuanceIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $eventId;

    private string $orderId;

    private string $reservationId;

    private string $ticketTypeId;

    private string $paymentReference;

    private ?string $secondOrderId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Estas pruebas solo pueden usar Firestore Emulator.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->markTestSkipped(
                'Estas pruebas requieren Firestore Emulator.'
            );
        }

        $this->firestore = app(FirestoreClient::class);

        $unique = bin2hex(random_bytes(8));

        $this->eventId = 'test-issue-' . $unique;
        $this->orderId = 'test-order-' . $unique;
        $this->reservationId = 'test-res-' . $unique;
        $this->ticketTypeId = 'general';

        // Cada prueba utiliza una referencia diferente.
        $this->paymentReference = 'test-payment-' . $unique;

        $now = new DateTimeImmutable();

        $timestamp = new Timestamp($now);

        $expiresAt = new Timestamp(
            $now->modify('+15 minutes')
        );

        $eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $eventDocument->set([
            'status' => 'published',
            'createdAt' => $timestamp,
        ]);

        $eventDocument
            ->collection('ticketTypes')
            ->document($this->ticketTypeId)
            ->set([
                'capacity' => 100,
                'sold' => 10,
                'reserved' => 2,
                'status' => 'active',
                'updatedAt' => $timestamp,
            ]);

        $eventDocument
            ->collection('reservations')
            ->document($this->reservationId)
            ->set([
                'orderId' => $this->orderId,
                'userId' => 'comprador-prueba',
                'eventId' => $this->eventId,
                'ticketTypeId' => $this->ticketTypeId,
                'quantity' => 2,
                'status' => 'active',
                'expiresAt' => $expiresAt,
                'createdAt' => $timestamp,
            ]);

        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->set([
                'userId' => 'comprador-prueba',
                'eventId' => $this->eventId,
                'ticketTypeId' => $this->ticketTypeId,
                'reservationId' => $this->reservationId,
                'quantity' => 2,
                'status' => 'pending',
                'createdAt' => $timestamp,
            ]);
    }

    /**
     * Emitir boletos y actualizar correctamente el inventario.
     */
    public function test_issues_tickets_and_updates_inventory(): void
    {
        $service = app(TicketIssuanceService::class);

        $result = $service->issueForVerifiedPayment(
            $this->orderId,
            $this->paymentReference
        );

        $this->assertSame('paid', $result['status']);
        $this->assertFalse($result['alreadyIssued']);
        $this->assertCount(2, $result['ticketIds']);

        $order = $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->snapshot()
            ->data();

        $this->assertSame('paid', $order['status']);

        $this->assertSame(
            $this->paymentReference,
            $order['paymentReference']
        );

        $inventory = $this->getInventory();

        $this->assertSame(12, $inventory['sold']);
        $this->assertSame(0, $inventory['reserved']);

        $reservation = $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reservations')
            ->document($this->reservationId)
            ->snapshot()
            ->data();

        $this->assertSame(
            'confirmed',
            $reservation['status']
        );

        $payment = $this->getPaymentDocument()
            ->snapshot();

        $this->assertTrue($payment->exists());

        $this->assertSame(
            $this->orderId,
            $payment->data()['orderId']
        );

        foreach ($result['ticketIds'] as $ticketId) {
            $ticket = $this->firestore
                ->collection('tickets')
                ->document($ticketId)
                ->snapshot();

            $this->assertTrue($ticket->exists());

            $data = $ticket->data();

            $this->assertSame(
                $this->orderId,
                $data['orderId']
            );

            $this->assertSame(
                'valid',
                $data['status']
            );

            $token = Crypt::decryptString(
                $data['qrTokenEncrypted']
            );

            $this->assertTrue(
                app(TicketQrToken::class)->verify(
                    $token,
                    $data['qrTokenHash']
                )
            );
        }
    }

    /**
     * Una confirmacion repetida no genera ventas duplicadas.
     */
    public function test_repeated_confirmation_does_not_duplicate(): void
    {
        $service = app(TicketIssuanceService::class);

        $first = $service->issueForVerifiedPayment(
            $this->orderId,
            $this->paymentReference
        );

        $second = $service->issueForVerifiedPayment(
            $this->orderId,
            $this->paymentReference
        );

        $this->assertFalse($first['alreadyIssued']);
        $this->assertTrue($second['alreadyIssued']);

        $this->assertSame(
            $first['ticketIds'],
            $second['ticketIds']
        );

        $inventory = $this->getInventory();

        $this->assertSame(12, $inventory['sold']);
        $this->assertSame(0, $inventory['reserved']);

        $this->assertTrue(
            $this->getPaymentDocument()->snapshot()->exists()
        );
    }

    /**
     * No emitir boletos de una reserva vencida.
     */
    public function test_rejects_expired_reservation(): void
    {
        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reservations')
            ->document($this->reservationId)
            ->update([
                [
                    'path' => 'expiresAt',
                    'value' => new Timestamp(
                        new DateTimeImmutable('-1 minute')
                    ),
                ],
            ]);

        $this->expectException(RuntimeException::class);

        app(TicketIssuanceService::class)
            ->issueForVerifiedPayment(
                $this->orderId,
                $this->paymentReference
            );
    }

    /**
     * No emitir boletos de un pedido cancelado.
     */
    public function test_rejects_cancelled_order(): void
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

        $this->expectException(RuntimeException::class);

        app(TicketIssuanceService::class)
            ->issueForVerifiedPayment(
                $this->orderId,
                $this->paymentReference
            );
    }

    /**
     * No aceptar otra referencia para un pedido pagado.
     */
    public function test_rejects_different_payment_reference(): void
    {
        $service = app(TicketIssuanceService::class);

        $service->issueForVerifiedPayment(
            $this->orderId,
            $this->paymentReference
        );

        $this->expectException(RuntimeException::class);

        $service->issueForVerifiedPayment(
            $this->orderId,
            'different-' . bin2hex(random_bytes(8))
        );
    }

    /**
     * Un pedido rechazado no modifica el inventario.
     */
    public function test_rejected_order_does_not_change_inventory(): void
    {
        $orderDocument = $this->firestore
            ->collection('orders')
            ->document($this->orderId);

        $orderDocument->update([
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
                'El pedido cancelado no debe emitir boletos.'
            );
        } catch (RuntimeException $exception) {
            $this->assertNotSame(
                '',
                $exception->getMessage()
            );
        }

        $inventory = $this->getInventory();

        $this->assertSame(10, $inventory['sold']);
        $this->assertSame(2, $inventory['reserved']);

        $this->assertSame(
            'cancelled',
            $orderDocument->snapshot()->data()['status']
        );

        $this->assertFalse(
            $this->getPaymentDocument()->snapshot()->exists()
        );

        $ticketIds = app(TicketIdGenerator::class)
            ->generateForOrder($this->orderId, 2);

        foreach ($ticketIds as $ticketId) {
            $this->assertFalse(
                $this->firestore
                    ->collection('tickets')
                    ->document($ticketId)
                    ->snapshot()
                    ->exists()
            );
        }
    }

    /**
     * Una referencia no puede pagar dos pedidos diferentes.
     */
    public function test_rejects_same_payment_for_another_order(): void
    {
        $service = app(TicketIssuanceService::class);

        // Confirmar correctamente el primer pedido.
        $first = $service->issueForVerifiedPayment(
            $this->orderId,
            $this->paymentReference
        );

        $this->assertSame('paid', $first['status']);

        // Preparar otro pedido de prueba.
        $this->secondOrderId = 'second-' . $this->orderId;

        $secondOrderDocument = $this->firestore
            ->collection('orders')
            ->document($this->secondOrderId);

        $secondOrderDocument->set([
            'userId' => 'otro-comprador',
            'eventId' => $this->eventId,
            'ticketTypeId' => $this->ticketTypeId,
            'reservationId' => 'otra-reserva',
            'quantity' => 1,
            'status' => 'pending',
        ]);

        // Intentar utilizar el pago del primer pedido.
        try {
            $service->issueForVerifiedPayment(
                $this->secondOrderId,
                $this->paymentReference
            );

            $this->fail(
                'Una referencia no debe confirmar dos pedidos.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'La referencia de pago ya pertenece a otro pedido.',
                $exception->getMessage()
            );
        }

        // El segundo pedido permanece pendiente.
        $secondOrder = $secondOrderDocument
            ->snapshot()
            ->data();

        $this->assertSame(
            'pending',
            $secondOrder['status']
        );

        // La referencia sigue perteneciendo al primer pedido.
        $paymentData = $this->getPaymentDocument()
            ->snapshot()
            ->data();

        $this->assertSame(
            $this->orderId,
            $paymentData['orderId']
        );

        // El inventario solo refleja la primera compra.
        $inventory = $this->getInventory();

        $this->assertSame(12, $inventory['sold']);
        $this->assertSame(0, $inventory['reserved']);
    }

    /**
     * Obtener el inventario del evento de prueba.
     */
    private function getInventory(): array
    {
        return $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('ticketTypes')
            ->document($this->ticketTypeId)
            ->snapshot()
            ->data();
    }

    /**
     * Obtener la referencia utilizada por esta prueba.
     */
    private function getPaymentDocument(): \Google\Cloud\Firestore\DocumentReference
    {
        $referenceId = hash(
            'sha256',
            $this->paymentReference
        );

        return $this->firestore
            ->collection('paymentReferences')
            ->document($referenceId);
    }

    /**
     * Eliminar todos los documentos temporales de las pruebas.
     */
    protected function tearDown(): void
    {
        if (isset($this->firestore, $this->orderId)) {
            $ticketIds = app(TicketIdGenerator::class)
                ->generateForOrder($this->orderId, 2);

            foreach ($ticketIds as $ticketId) {
                $this->firestore
                    ->collection('tickets')
                    ->document($ticketId)
                    ->delete();
            }

            // Eliminar la referencia de pago de esta prueba.
            $this->getPaymentDocument()->delete();

            // Eliminar el segundo pedido si fue creado.
            if ($this->secondOrderId !== null) {
                $this->firestore
                    ->collection('orders')
                    ->document($this->secondOrderId)
                    ->delete();
            }

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
                ->document($this->ticketTypeId)
                ->delete();

            $event->delete();
        }

        parent::tearDown();
    }
}
