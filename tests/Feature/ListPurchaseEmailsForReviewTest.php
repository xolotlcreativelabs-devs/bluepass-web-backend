<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ListPurchaseEmailsForReviewTest extends TestCase
{
    private FirestoreClient $firestore;

    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Trabajar solamente con Firestore Emulator.
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
    }

    /**
     * Crear un correo temporal para las pruebas.
     */
    private function createEmail(
        string $status,
        string $type = 'purchase_confirmation'
    ): array {
        $suffix = bin2hex(random_bytes(8));

        $orderId = 'order-review-' . $suffix;

        $emailId = hash(
            'sha256',
            'purchase_confirmation:' . $orderId
        );

        $timestamp = new Timestamp(
            new DateTimeImmutable()
        );

        $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document($emailId)
            ->set([
                'orderId' => $orderId,
                'userId' => 'buyer-review-' . $suffix,
                'type' => $type,
                'recipientName' => 'Comprador BluePass',
                'recipientEmail' => 'comprador@example.com',
                'ticketCount' => 2,
                'status' => $status,
                'attempts' => 1,
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
                'processingStartedAt' => $timestamp,
                'acceptedAt' => null,
                'lastErrorCode' => $status === 'uncertain'
                    ? 'mail_send_exception'
                    : null,
            ]);

        $this->createdIds[] = $emailId;

        return [
            'id' => $emailId,
            'orderId' => $orderId,
        ];
    }

    /**
     * Obtener los datos de un correo.
     */
    private function emailData(string $emailId): array
    {
        $snapshot = $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document($emailId)
            ->snapshot();

        $this->assertTrue($snapshot->exists());

        return $snapshot->data();
    }

    /**
     * Ejecutar el comando de revision.
     */
    private function runCommand(string $limit = '100'): int
    {
        return Artisan::call(
            'bluepass:emails-review',
            ['--limit' => $limit]
        );
    }

    /**
     * Comprobar que la consulta no modifico el correo.
     */
    private function assertEmailUnchanged(
        string $emailId,
        array $before
    ): void {
        $after = $this->emailData($emailId);

        $this->assertSame(
            $before['status'],
            $after['status']
        );

        $this->assertSame(
            $before['attempts'],
            $after['attempts']
        );

        $this->assertSame(
            $before['orderId'],
            $after['orderId']
        );

        $this->assertSame(
            $before['lastErrorCode'],
            $after['lastErrorCode']
        );

        $this->assertSame(
            $before['updatedAt']->get()->format('U.u'),
            $after['updatedAt']->get()->format('U.u')
        );

        $this->assertSame(
            $before['createdAt']->get()->format('U.u'),
            $after['createdAt']->get()->format('U.u')
        );

        $this->assertSame(
            $before['processingStartedAt']->get()->format('U.u'),
            $after['processingStartedAt']->get()->format('U.u')
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
            'bluepass:emails-review',
            Artisan::output()
        );

        Mail::assertNothingSent();
    }

    /**
     * Consultar un correo en procesamiento.
     */
    public function test_command_lists_processing_email(): void
    {
        $email = $this->createEmail('processing');

        $before = $this->emailData($email['id']);

        $exitCode = $this->runCommand();

        $this->assertSame(0, $exitCode);

        $this->assertStringContainsString(
            'processing',
            Artisan::output()
        );

        $after = $this->emailData($email['id']);

        $this->assertSame(
            'processing',
            $after['status']
        );

        $this->assertSame(
            $email['orderId'],
            $after['orderId']
        );

        $this->assertEmailUnchanged(
            $email['id'],
            $before
        );

        Mail::assertNothingSent();
    }

    /**
     * Consultar un correo con resultado incierto.
     */
    public function test_command_lists_uncertain_email(): void
    {
        $email = $this->createEmail('uncertain');

        $before = $this->emailData($email['id']);

        $exitCode = $this->runCommand();

        $this->assertSame(0, $exitCode);

        $this->assertStringContainsString(
            'uncertain',
            Artisan::output()
        );

        // Verificar el error directamente en Firestore.
        $after = $this->emailData($email['id']);

        $this->assertSame(
            'uncertain',
            $after['status']
        );

        $this->assertSame(
            'mail_send_exception',
            $after['lastErrorCode']
        );

        $this->assertEmailUnchanged(
            $email['id'],
            $before
        );

        Mail::assertNothingSent();
    }

    /**
     * Consultar ambos estados sin modificarlos.
     */
    public function test_command_handles_both_review_statuses(): void
    {
        $processing = $this->createEmail('processing');
        $uncertain = $this->createEmail('uncertain');

        $processingBefore = $this->emailData(
            $processing['id']
        );

        $uncertainBefore = $this->emailData(
            $uncertain['id']
        );

        $exitCode = $this->runCommand();

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();

        $this->assertStringContainsString(
            'processing',
            $output
        );

        $this->assertStringContainsString(
            'uncertain',
            $output
        );

        $this->assertEmailUnchanged(
            $processing['id'],
            $processingBefore
        );

        $this->assertEmailUnchanged(
            $uncertain['id'],
            $uncertainBefore
        );

        Mail::assertNothingSent();
    }

    /**
     * Un correo pendiente no debe modificarse.
     */
    public function test_pending_email_is_not_modified(): void
    {
        $email = $this->createEmail('pending');

        $before = $this->emailData($email['id']);

        $exitCode = $this->runCommand();

        $this->assertSame(0, $exitCode);

        $this->assertEmailUnchanged(
            $email['id'],
            $before
        );

        Mail::assertNothingSent();
    }

    /**
     * Un correo aceptado no debe modificarse.
     */
    public function test_accepted_email_is_not_modified(): void
    {
        $email = $this->createEmail('accepted');

        $before = $this->emailData($email['id']);

        $exitCode = $this->runCommand();

        $this->assertSame(0, $exitCode);

        $this->assertEmailUnchanged(
            $email['id'],
            $before
        );

        Mail::assertNothingSent();
    }

    /**
     * Rechazar el limite cero.
     */
    public function test_zero_limit_is_rejected(): void
    {
        $exitCode = $this->runCommand('0');

        $this->assertSame(2, $exitCode);

        $this->assertStringContainsString(
            'El limite debe estar entre 1 y 100.',
            Artisan::output()
        );

        Mail::assertNothingSent();
    }

    /**
     * Rechazar limites superiores a cien.
     */
    public function test_excessive_limit_is_rejected(): void
    {
        $exitCode = $this->runCommand('101');

        $this->assertSame(2, $exitCode);

        $this->assertStringContainsString(
            'El limite debe estar entre 1 y 100.',
            Artisan::output()
        );

        Mail::assertNothingSent();
    }

    /**
     * Rechazar limites que no sean enteros.
     */
    public function test_invalid_limit_is_rejected(): void
    {
        $exitCode = $this->runCommand('abc');

        $this->assertSame(2, $exitCode);

        $this->assertStringContainsString(
            'El limite debe ser un numero entero.',
            Artisan::output()
        );

        Mail::assertNothingSent();
    }

    /**
     * No permitir consultas fuera del emulador.
     */
    public function test_command_rejects_wrong_emulator_host(): void
    {
        $originalHost = getenv(
            'FIRESTORE_EMULATOR_HOST'
        );

        try {
            putenv(
                'FIRESTORE_EMULATOR_HOST=127.0.0.1:9999'
            );

            $exitCode = $this->runCommand();

            $this->assertSame(1, $exitCode);

            $this->assertStringContainsString(
                'Este comando solo esta habilitado para Firestore Emulator.',
                Artisan::output()
            );

            Mail::assertNothingSent();
        } finally {
            if ($originalHost === false) {
                putenv('FIRESTORE_EMULATOR_HOST');
            } else {
                putenv(
                    'FIRESTORE_EMULATOR_HOST=' .
                    $originalHost
                );
            }
        }
    }

    /**
     * Eliminar exclusivamente los documentos de prueba.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                foreach ($this->createdIds as $emailId) {
                    $this->firestore
                        ->collection('purchaseEmailOutbox')
                        ->document($emailId)
                        ->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
