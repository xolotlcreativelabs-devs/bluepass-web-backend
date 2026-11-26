<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ListPendingPurchaseEmailsTest extends TestCase
{
    private FirestoreClient $firestore;

    private array $createdIds = [];

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

        // Compartir el cliente con los comandos de Laravel.
        $this->app->instance(
            FirestoreClient::class,
            $this->firestore
        );

        Mail::fake();
    }

    /**
     * Crear un registro temporal para las pruebas.
     */
    private function createEmail(
        string $status = 'pending',
        string $type = 'purchase_confirmation'
    ): array {
        $suffix = bin2hex(random_bytes(8));

        $orderId = 'order-command-' . $suffix;

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
                'userId' => 'buyer-command-' . $suffix,
                'type' => $type,
                'recipientName' => 'Comprador de prueba',
                'recipientEmail' => 'comprador@example.com',
                'ticketCount' => 2,
                'status' => $status,
                'attempts' => 0,
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
                'processingStartedAt' => null,
                'acceptedAt' => null,
                'lastErrorCode' => null,
            ]);

        $this->createdIds[] = $emailId;

        return [
            'id' => $emailId,
            'orderId' => $orderId,
        ];
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
            'bluepass:emails-pending',
            Artisan::output()
        );

        Mail::assertNothingSent();
    }

    /**
     * Mostrar un correo pendiente sin enviarlo.
     */
    public function test_command_lists_pending_email(): void
    {
        $email = $this->createEmail();

        $exitCode = Artisan::call(
            'bluepass:emails-pending',
            ['--limit' => '100']
        );

        $this->assertSame(0, $exitCode);

        // Capturar directamente la tabla generada por Artisan.
        $output = Artisan::output();

        $this->assertStringContainsString(
            $email['id'],
            $output
        );

        $this->assertStringContainsString(
            $email['orderId'],
            $output
        );

        $this->assertStringContainsString(
            'pending',
            $output
        );

        $document = $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document($email['id'])
            ->snapshot();

        $this->assertTrue($document->exists());

        $data = $document->data();

        $this->assertSame('pending', $data['status']);
        $this->assertSame(0, $data['attempts']);
        $this->assertNull($data['acceptedAt']);

        Mail::assertNothingSent();
    }

    /**
     * No mostrar un correo ya aceptado.
     */
    public function test_command_excludes_accepted_email(): void
    {
        $email = $this->createEmail('accepted');

        $exitCode = Artisan::call(
            'bluepass:emails-pending',
            ['--limit' => '100']
        );

        $this->assertSame(0, $exitCode);

        $this->assertStringNotContainsString(
            $email['id'],
            Artisan::output()
        );

        $snapshot = $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document($email['id'])
            ->snapshot();

        $this->assertSame(
            'accepted',
            $snapshot->data()['status']
        );

        Mail::assertNothingSent();
    }

    /**
     * No mostrar correos de otros tipos.
     */
    public function test_command_excludes_other_email_types(): void
    {
        $email = $this->createEmail(
            'pending',
            'other_notification'
        );

        $exitCode = Artisan::call(
            'bluepass:emails-pending',
            ['--limit' => '100']
        );

        $this->assertSame(0, $exitCode);

        $this->assertStringNotContainsString(
            $email['id'],
            Artisan::output()
        );

        Mail::assertNothingSent();
    }

    /**
     * Rechazar un limite igual a cero.
     */
    public function test_command_rejects_zero_limit(): void
    {
        $exitCode = Artisan::call(
            'bluepass:emails-pending',
            ['--limit' => '0']
        );

        $this->assertSame(2, $exitCode);

        $this->assertStringContainsString(
            'El limite debe estar entre 1 y 100.',
            Artisan::output()
        );

        Mail::assertNothingSent();
    }

    /**
     * Rechazar limites superiores al maximo.
     */
    public function test_command_rejects_excessive_limit(): void
    {
        $exitCode = Artisan::call(
            'bluepass:emails-pending',
            ['--limit' => '101']
        );

        $this->assertSame(2, $exitCode);

        $this->assertStringContainsString(
            'El limite debe estar entre 1 y 100.',
            Artisan::output()
        );

        Mail::assertNothingSent();
    }

    /**
     * Rechazar limites que no sean numeros.
     */
    public function test_command_rejects_invalid_limit(): void
    {
        $exitCode = Artisan::call(
            'bluepass:emails-pending',
            ['--limit' => 'abc']
        );

        $this->assertSame(2, $exitCode);

        $this->assertStringContainsString(
            'El limite debe ser un numero entero.',
            Artisan::output()
        );

        Mail::assertNothingSent();
    }

    /**
     * Eliminar los registros temporales del emulador.
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
