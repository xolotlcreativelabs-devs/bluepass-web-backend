<?php

namespace Tests\Feature;

use App\Services\PurchaseEmailProcessorService;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class PurchaseEmailProcessorFailureTest extends TestCase
{
    private FirestoreClient $firestore;

    private PurchaseEmailProcessorService $processor;

    private string $orderId;
    private string $buyerId;
    private string $emailId;

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutar exclusivamente contra Firestore Emulator.
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

        $this->processor = new PurchaseEmailProcessorService(
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->orderId = 'order-mail-failure-' . $suffix;
        $this->buyerId = 'buyer-mail-failure-' . $suffix;

        $this->emailId = hash(
            'sha256',
            'purchase_confirmation:' . $this->orderId
        );

        $timestamp = new Timestamp(
            new DateTimeImmutable()
        );

        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->set([
                'userId' => $this->buyerId,
                'status' => 'paid',
                'issuedTicketIds' => [
                    'ticket-failure-001',
                    'ticket-failure-002',
                ],
                'buyer' => [
                    'id' => $this->buyerId,
                    'name' => 'Comprador BluePass',
                    'email' => 'comprador@example.com',
                ],
                'paidAt' => $timestamp,
            ]);

        $this->emailDocument()->set([
            'orderId' => $this->orderId,
            'userId' => $this->buyerId,
            'type' => 'purchase_confirmation',
            'recipientName' => 'Comprador BluePass',
            'recipientEmail' => 'comprador@example.com',
            'ticketCount' => 2,
            'status' => 'pending',
            'attempts' => 0,
            'createdAt' => $timestamp,
            'updatedAt' => $timestamp,
            'processingStartedAt' => null,
            'acceptedAt' => null,
            'lastErrorCode' => null,
        ]);

        // El transporte nunca contactara un proveedor real.
        Mail::fake();

        config([
            'bluepass.purchase_emails_enabled' => true,
            'mail.default' => 'array',
        ]);
    }

    /**
     * Obtener el documento del correo pendiente.
     */
    private function emailDocument()
    {
        return $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document($this->emailId);
    }

    /**
     * Obtener el estado guardado en Firestore.
     */
    private function emailData(): array
    {
        return $this->emailDocument()
            ->snapshot()
            ->data();
    }

    /**
     * Simular un error del servicio de correo.
     */
    private function simulateTransportFailure(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->with('comprador@example.com')
            ->andThrow(
                new RuntimeException(
                    'Error simulado del proveedor de correo.'
                )
            );
    }

    /**
     * Un error durante el envio deja el resultado incierto.
     */
    public function test_transport_failure_marks_email_as_uncertain(): void
    {
        $this->simulateTransportFailure();

        $result = $this->processor->process(
            $this->emailId
        );

        $this->assertSame(
            $this->emailId,
            $result['emailId']
        );

        $this->assertSame(
            'uncertain',
            $result['status']
        );

        $this->assertSame(
            'El resultado del envio requiere revision.',
            $result['message']
        );

        $email = $this->emailData();

        $this->assertSame(
            'uncertain',
            $email['status']
        );

        $this->assertSame(
            1,
            $email['attempts']
        );

        $this->assertSame(
            'mail_send_exception',
            $email['lastErrorCode']
        );

        $this->assertInstanceOf(
            Timestamp::class,
            $email['processingStartedAt']
        );

        $this->assertInstanceOf(
            Timestamp::class,
            $email['updatedAt']
        );

        $this->assertNull(
            $email['acceptedAt']
        );

        // No exponer detalles internos del proveedor.
        $this->assertStringNotContainsString(
            'Error simulado',
            $result['message']
        );
    }

    /**
     * Un correo incierto no debe enviarse nuevamente.
     */
    public function test_uncertain_email_is_not_retried(): void
    {
        $this->simulateTransportFailure();

        $first = $this->processor->process(
            $this->emailId
        );

        $this->assertSame(
            'uncertain',
            $first['status']
        );

        $before = $this->emailData();

        // La segunda llamada no debe contactar el transporte.
        $second = $this->processor->process(
            $this->emailId
        );

        $this->assertSame(
            'uncertain',
            $second['status']
        );

        $this->assertTrue(
            $second['alreadyProcessed']
        );

        $after = $this->emailData();

        $this->assertSame(
            1,
            $after['attempts']
        );

        $this->assertSame(
            $before['processingStartedAt']
                ->get()
                ->format('U.u'),
            $after['processingStartedAt']
                ->get()
                ->format('U.u')
        );

        $this->assertSame(
            'mail_send_exception',
            $after['lastErrorCode']
        );
    }

    /**
     * Una interrupcion deja el proceso bloqueado
     * hasta que exista una revision controlada.
     */
    public function test_interrupted_processing_is_not_retried(): void
    {
        $timestamp = new Timestamp(
            new DateTimeImmutable()
        );

        $this->emailDocument()->update([
            [
                'path' => 'status',
                'value' => 'processing',
            ],
            [
                'path' => 'attempts',
                'value' => 1,
            ],
            [
                'path' => 'processingStartedAt',
                'value' => $timestamp,
            ],
            [
                'path' => 'claimId',
                'value' => 'claim-interrupted-test',
            ],
        ]);

        $result = $this->processor->process(
            $this->emailId
        );

        $this->assertSame(
            'processing',
            $result['status']
        );

        $this->assertTrue(
            $result['alreadyProcessed']
        );

        $email = $this->emailData();

        $this->assertSame(
            'processing',
            $email['status']
        );

        $this->assertSame(
            1,
            $email['attempts']
        );

        $this->assertSame(
            'claim-interrupted-test',
            $email['claimId']
        );

        Mail::assertNothingSent();
    }

    /**
     * Eliminar solamente los documentos de esta prueba.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore, $this->emailId)) {
                $this->emailDocument()->delete();

                $this->firestore
                    ->collection('orders')
                    ->document($this->orderId)
                    ->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
