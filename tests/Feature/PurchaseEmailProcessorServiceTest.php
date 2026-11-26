<?php

namespace Tests\Feature;

use App\Mail\PurchaseConfirmationMail;
use App\Services\PurchaseEmailProcessorService;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class PurchaseEmailProcessorServiceTest extends TestCase
{
    private FirestoreClient $firestore;

    private PurchaseEmailProcessorService $processor;

    private string $orderId;
    private string $buyerId;
    private string $emailId;

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

        $this->processor = new PurchaseEmailProcessorService(
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->orderId = 'order-processor-' . $suffix;
        $this->buyerId = 'buyer-processor-' . $suffix;

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
                    'ticket-processor-001',
                    'ticket-processor-002',
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

        // Nunca utilizar un proveedor real en estas pruebas.
        Mail::fake();

        config([
            'bluepass.purchase_emails_enabled' => false,
            'mail.default' => 'array',
        ]);
    }

    /**
     * Consultar el registro del correo.
     */
    private function emailDocument()
    {
        return $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document($this->emailId);
    }

    /**
     * Consultar los datos guardados del correo.
     */
    private function emailData(): array
    {
        return $this->emailDocument()
            ->snapshot()
            ->data();
    }

    /**
     * Habilitar solamente el procesamiento de prueba.
     */
    private function enableForTest(): void
    {
        config([
            'bluepass.purchase_emails_enabled' => true,
            'mail.default' => 'array',
        ]);
    }

    /**
     * No procesar correos cuando el servicio esta deshabilitado.
     */
    public function test_disabled_service_does_not_send_mail(): void
    {
        $result = $this->processor->process(
            $this->emailId
        );

        $this->assertSame(
            'disabled',
            $result['status']
        );

        $email = $this->emailData();

        $this->assertSame('pending', $email['status']);
        $this->assertSame(0, $email['attempts']);

        Mail::assertNothingSent();
    }

    /**
     * No utilizar el transportador log para procesar mensajes.
     */
    public function test_log_mailer_does_not_process_email(): void
    {
        $this->enableForTest();

        config([
            'mail.default' => 'log',
        ]);

        $result = $this->processor->process(
            $this->emailId
        );

        $this->assertSame(
            'disabled',
            $result['status']
        );

        $this->assertSame(
            'pending',
            $this->emailData()['status']
        );

        Mail::assertNothingSent();
    }

    /**
     * Un pedido pagado con boletos puede procesarse una vez.
     */
    public function test_paid_order_processes_confirmation_email(): void
    {
        $this->enableForTest();

        $result = $this->processor->process(
            $this->emailId
        );

        $this->assertSame(
            $this->emailId,
            $result['emailId']
        );

        $this->assertSame(
            'accepted',
            $result['status']
        );

        $email = $this->emailData();

        $this->assertSame('accepted', $email['status']);
        $this->assertSame(1, $email['attempts']);

        $this->assertInstanceOf(
            Timestamp::class,
            $email['processingStartedAt']
        );

        $this->assertInstanceOf(
            Timestamp::class,
            $email['acceptedAt']
        );

        $this->assertNull($email['lastErrorCode']);

        Mail::assertSent(
            PurchaseConfirmationMail::class,
            function (
                PurchaseConfirmationMail $mail
            ): bool {
                return $mail->hasTo(
                    'comprador@example.com'
                ) &&
                    $mail->orderId === $this->orderId &&
                    $mail->ticketCount === 2 &&
                    $mail->buyerName ===
                        'Comprador BluePass';
            }
        );

        Mail::assertSentCount(1);
    }

    /**
     * No procesar dos veces el mismo correo.
     */
    public function test_repeated_processing_does_not_send_again(): void
    {
        $this->enableForTest();

        $first = $this->processor->process(
            $this->emailId
        );

        $second = $this->processor->process(
            $this->emailId
        );

        $this->assertSame(
            'accepted',
            $first['status']
        );

        $this->assertSame(
            'accepted',
            $second['status']
        );

        $this->assertTrue(
            $second['alreadyProcessed']
        );

        $this->assertSame(
            1,
            $this->emailData()['attempts']
        );

        Mail::assertSentCount(1);
    }

    /**
     * Un pedido pendiente no puede generar un correo.
     */
    public function test_pending_order_cannot_send_email(): void
    {
        $this->enableForTest();

        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->update([
                [
                    'path' => 'status',
                    'value' => 'pending',
                ],
            ]);

        $this->expectException(
            RuntimeException::class
        );

        try {
            $this->processor->process(
                $this->emailId
            );
        } finally {
            $this->assertSame(
                'pending',
                $this->emailData()['status']
            );

            $this->assertSame(
                0,
                $this->emailData()['attempts']
            );

            Mail::assertNothingSent();
        }
    }

    /**
     * Un pedido sin boletos emitidos no puede enviar correo.
     */
    public function test_order_without_tickets_cannot_send_email(): void
    {
        $this->enableForTest();

        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->update([
                [
                    'path' => 'issuedTicketIds',
                    'value' => [],
                ],
            ]);

        $this->expectException(
            RuntimeException::class
        );

        try {
            $this->processor->process(
                $this->emailId
            );
        } finally {
            $this->assertSame(
                'pending',
                $this->emailData()['status']
            );

            Mail::assertNothingSent();
        }
    }

    /**
     * El destinatario debe coincidir con el comprador.
     */
    public function test_different_recipient_cannot_send_email(): void
    {
        $this->enableForTest();

        $this->emailDocument()->update([
            [
                'path' => 'recipientEmail',
                'value' => 'otra-persona@example.com',
            ],
        ]);

        $this->expectException(
            RuntimeException::class
        );

        try {
            $this->processor->process(
                $this->emailId
            );
        } finally {
            $this->assertSame(
                'pending',
                $this->emailData()['status']
            );

            Mail::assertNothingSent();
        }
    }

    /**
     * Un correo ya en proceso no debe enviarse de nuevo.
     */
    public function test_processing_email_is_not_sent_again(): void
    {
        $this->enableForTest();

        $this->emailDocument()->update([
            [
                'path' => 'status',
                'value' => 'processing',
            ],
            [
                'path' => 'attempts',
                'value' => 1,
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

        $this->assertSame(
            1,
            $this->emailData()['attempts']
        );

        Mail::assertNothingSent();
    }

    /**
     * Un resultado incierto requiere revision.
     */
    public function test_uncertain_email_is_not_sent_again(): void
    {
        $this->enableForTest();

        $this->emailDocument()->update([
            [
                'path' => 'status',
                'value' => 'uncertain',
            ],
            [
                'path' => 'attempts',
                'value' => 1,
            ],
        ]);

        $result = $this->processor->process(
            $this->emailId
        );

        $this->assertSame(
            'uncertain',
            $result['status']
        );

        $this->assertTrue(
            $result['alreadyProcessed']
        );

        $this->assertSame(
            1,
            $this->emailData()['attempts']
        );

        Mail::assertNothingSent();
    }

    /**
     * Rechazar identificadores invalidos.
     */
    public function test_invalid_email_id_is_rejected(): void
    {
        $this->enableForTest();

        $this->expectException(
            RuntimeException::class
        );

        try {
            $this->processor->process(
                'correo-invalido'
            );
        } finally {
            Mail::assertNothingSent();
        }
    }

    /**
     * Un correo inexistente no debe procesarse.
     */
    public function test_missing_email_is_rejected(): void
    {
        $this->enableForTest();

        $missingId = hash(
            'sha256',
            'missing-email-' . bin2hex(random_bytes(8))
        );

        $this->expectException(
            RuntimeException::class
        );

        try {
            $this->processor->process(
                $missingId
            );
        } finally {
            Mail::assertNothingSent();
        }
    }

    /**
     * Eliminar los documentos temporales del emulador.
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
