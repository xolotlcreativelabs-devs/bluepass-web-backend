<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProcessPendingPurchaseEmailTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $emailId;
    private string $orderId;
    private string $buyerId;

    protected function setUp(): void
    {
        parent::setUp();

        // Utilizar exclusivamente Firestore Emulator.
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

        $this->orderId = 'order-process-command-' . $suffix;
        $this->buyerId = 'buyer-process-command-' . $suffix;

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
                    'ticket-command-001',
                    'ticket-command-002',
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

        Mail::fake();

        config([
            'bluepass.purchase_emails_enabled' => false,
            'mail.default' => 'log',
        ]);
    }

    /**
     * Obtener el documento del correo.
     */
    private function emailDocument()
    {
        return $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document($this->emailId);
    }

    /**
     * Consultar el estado del correo.
     */
    private function emailData(): array
    {
        return $this->emailDocument()
            ->snapshot()
            ->data();
    }

    /**
     * Ejecutar el comando con parametros controlados.
     */
    private function runCommand(
        ?string $emailId = null,
        bool $execute = false,
        ?string $confirmation = null
    ): int {
        $arguments = [
            'emailId' => $emailId ?? $this->emailId,
        ];

        if ($execute) {
            $arguments['--execute'] = true;
        }

        if ($confirmation !== null) {
            $arguments['--confirm'] = $confirmation;
        }

        return Artisan::call(
            'bluepass:email-process',
            $arguments
        );
    }

    /**
     * Verificar que Laravel reconoce el comando.
     */
    public function test_command_is_registered(): void
    {
        $exitCode = Artisan::call(
            'list',
            ['--raw' => true]
        );

        $this->assertSame(0, $exitCode);

        $this->assertStringContainsString(
            'bluepass:email-process',
            Artisan::output()
        );

        Mail::assertNothingSent();
    }

    /**
     * Rechazar identificadores incorrectos.
     */
    public function test_invalid_email_id_is_rejected(): void
    {
        $exitCode = $this->runCommand(
            'correo-invalido'
        );

        $this->assertSame(2, $exitCode);

        $this->assertStringContainsString(
            'El identificador del correo no es valido.',
            Artisan::output()
        );

        $this->assertSame(
            'pending',
            $this->emailData()['status']
        );

        Mail::assertNothingSent();
    }

    /**
     * Rechazar correos inexistentes.
     */
    public function test_missing_email_is_rejected(): void
    {
        $missingId = hash(
            'sha256',
            'missing-command-' . bin2hex(random_bytes(8))
        );

        $exitCode = $this->runCommand(
            $missingId
        );

        $this->assertSame(1, $exitCode);

        $this->assertStringContainsString(
            'El correo pendiente no existe.',
            Artisan::output()
        );

        Mail::assertNothingSent();
    }

    /**
     * El modo consulta no debe modificar ni enviar.
     */
    public function test_preview_does_not_process_email(): void
    {
        $before = $this->emailData();

        $exitCode = $this->runCommand();

        $this->assertSame(0, $exitCode);

        $this->assertStringContainsString(
            'Modo consulta: no se proceso ni envio ningun correo.',
            Artisan::output()
        );

        // Comprobar directamente el pedido en Firestore.
        $email = $this->emailData();

        $this->assertSame(
            $this->orderId,
            $email['orderId']
        );

        $this->assertSame(
            $this->buyerId,
            $email['userId']
        );

        $this->assertSame(
            'pending',
            $email['status']
        );

        $this->assertSame(
            0,
            $email['attempts']
        );

        $this->assertNull(
            $email['processingStartedAt']
        );

        $this->assertNull(
            $email['acceptedAt']
        );

        // La consulta no debe cambiar el registro.
        $this->assertSame(
            $before['createdAt']->get()->format('U.u'),
            $email['createdAt']->get()->format('U.u')
        );

        $this->assertSame(
            $before['updatedAt']->get()->format('U.u'),
            $email['updatedAt']->get()->format('U.u')
        );

        Mail::assertNothingSent();
    }

    /**
     * Ejecutar requiere confirmacion adicional.
     */
    public function test_execute_without_confirmation_is_rejected(): void
    {
        $exitCode = $this->runCommand(
            execute: true
        );

        $this->assertSame(2, $exitCode);

        $this->assertStringContainsString(
            'Falta la confirmacion explicita de ejecucion.',
            Artisan::output()
        );

        $this->assertSame(
            'pending',
            $this->emailData()['status']
        );

        Mail::assertNothingSent();
    }

    /**
     * Rechazar confirmaciones incorrectas.
     */
    public function test_wrong_confirmation_is_rejected(): void
    {
        $exitCode = $this->runCommand(
            execute: true,
            confirmation: 'CONFIRMACION_INCORRECTA'
        );

        $this->assertSame(2, $exitCode);

        $this->assertSame(
            'pending',
            $this->emailData()['status']
        );

        Mail::assertNothingSent();
    }

    /**
     * Impedir ejecuciones con el servicio deshabilitado.
     */
    public function test_disabled_service_blocks_execution(): void
    {
        $exitCode = $this->runCommand(
            execute: true,
            confirmation: 'PROCESS_PURCHASE_EMAIL'
        );

        $this->assertSame(1, $exitCode);

        $this->assertStringContainsString(
            'El procesamiento de correos esta deshabilitado.',
            Artisan::output()
        );

        $email = $this->emailData();

        $this->assertSame('pending', $email['status']);
        $this->assertSame(0, $email['attempts']);

        Mail::assertNothingSent();
    }

    /**
     * El mailer log no puede procesar correos.
     */
    public function test_log_mailer_blocks_execution(): void
    {
        config([
            'bluepass.purchase_emails_enabled' => true,
            'mail.default' => 'log',
        ]);

        $exitCode = $this->runCommand(
            execute: true,
            confirmation: 'PROCESS_PURCHASE_EMAIL'
        );

        $this->assertSame(1, $exitCode);

        $this->assertStringContainsString(
            'No existe un proveedor de correo habilitado.',
            Artisan::output()
        );

        $this->assertSame(
            'pending',
            $this->emailData()['status']
        );

        Mail::assertNothingSent();
    }

    /**
     * El mailer array tampoco se acepta desde el comando.
     */
    public function test_array_mailer_blocks_execution(): void
    {
        config([
            'bluepass.purchase_emails_enabled' => true,
            'mail.default' => 'array',
        ]);

        $exitCode = $this->runCommand(
            execute: true,
            confirmation: 'PROCESS_PURCHASE_EMAIL'
        );

        $this->assertSame(1, $exitCode);

        $email = $this->emailData();

        $this->assertSame('pending', $email['status']);
        $this->assertSame(0, $email['attempts']);

        Mail::assertNothingSent();
    }

    /**
     * Los correos aceptados no deben reenviarse.
     */
    public function test_accepted_email_cannot_be_processed_again(): void
    {
        $this->emailDocument()->update([
            [
                'path' => 'status',
                'value' => 'accepted',
            ],
            [
                'path' => 'attempts',
                'value' => 1,
            ],
        ]);

        $exitCode = $this->runCommand(
            execute: true,
            confirmation: 'PROCESS_PURCHASE_EMAIL'
        );

        $this->assertSame(1, $exitCode);

        $this->assertStringContainsString(
            'Solo se pueden iniciar correos pendientes.',
            Artisan::output()
        );

        $email = $this->emailData();

        $this->assertSame('accepted', $email['status']);
        $this->assertSame(1, $email['attempts']);

        Mail::assertNothingSent();
    }

    /**
     * Rechazar registros de otros tipos.
     */
    public function test_other_email_type_is_rejected(): void
    {
        $this->emailDocument()->update([
            [
                'path' => 'type',
                'value' => 'other_notification',
            ],
        ]);

        $exitCode = $this->runCommand();

        $this->assertSame(1, $exitCode);

        $this->assertStringContainsString(
            'El registro no corresponde a una confirmacion de compra.',
            Artisan::output()
        );

        Mail::assertNothingSent();
    }

    /**
     * Eliminar solo los documentos de estas pruebas.
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
