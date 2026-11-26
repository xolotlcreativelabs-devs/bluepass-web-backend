<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PurchaseEmailConcurrencyTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $emailDocument;

    private DocumentReference $orderDocument;

    private string $emailId;

    private string $orderId;

    private string $buyerId;

    protected function setUp(): void
    {
        parent::setUp();

        // Estas pruebas solo pueden usar Firestore Emulator.
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

        $suffix = bin2hex(random_bytes(8));

        $this->orderId = 'order-email-concurrent-' . $suffix;
        $this->buyerId = 'buyer-email-concurrent-' . $suffix;

        $this->emailId = hash(
            'sha256',
            'purchase_confirmation:' . $this->orderId
        );

        $timestamp = new Timestamp(
            new DateTimeImmutable()
        );

        $this->orderDocument = $this->firestore
            ->collection('orders')
            ->document($this->orderId);

        $this->emailDocument = $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document($this->emailId);

        // Crear un pedido de prueba ya pagado.
        $this->orderDocument->set([
            'userId' => $this->buyerId,
            'status' => 'paid',
            'issuedTicketIds' => [
                'ticket-concurrent-001',
                'ticket-concurrent-002',
            ],
            'buyer' => [
                'id' => $this->buyerId,
                'name' => 'Comprador BluePass',
                'email' => 'comprador@example.com',
            ],
            'paidAt' => $timestamp,
        ]);

        // Crear un unico correo pendiente.
        $this->emailDocument->set([
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
    }

    /**
     * Preparar un proceso PHP con envio simulado.
     */
    private function worker(string $startAt): Process
    {
        $process = new Process(
            [
                PHP_BINARY,
                base_path(
                    'tests/Support/PurchaseEmailConcurrencyWorker.php'
                ),
                $this->emailId,
                $startAt,
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'FIRESTORE_EMULATOR_HOST' => '127.0.0.1:8080',
                'GOOGLE_CLOUD_PROJECT' => 'demo-bluepass-hu04',
                'MAIL_MAILER' => 'array',
            ]
        );

        $process->setTimeout(60);

        return $process;
    }

    /**
     * Ejecutar dos procesos sobre el mismo correo.
     */
    private function runTogether(): array
    {
        // Ambos procesos comparten el mismo instante de inicio.
        $startAt = (string) (
            (int) floor(microtime(true) * 1000) + 2000
        );

        $first = $this->worker($startAt);
        $second = $this->worker($startAt);

        $first->start();
        $second->start();

        $first->wait();
        $second->wait();

        return [
            $this->readResult($first, 1),
            $this->readResult($second, 2),
        ];
    }

    /**
     * Ejecutar un solo proceso.
     */
    private function runOnce(): array
    {
        $startAt = (string) (
            (int) floor(microtime(true) * 1000) + 300
        );

        $process = $this->worker($startAt);
        $process->run();

        return $this->readResult($process, 1);
    }

    /**
     * Leer la respuesta JSON del trabajador.
     */
    private function readResult(
        Process $process,
        int $number
    ): array {
        $output = trim($process->getOutput());
        $errors = trim($process->getErrorOutput());

        $this->assertNotSame(
            '',
            $output,
            'El proceso ' . $number .
                ' no devolvio una respuesta.' .
                "\nErrores: " . $errors
        );

        $result = json_decode(
            $output,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertIsArray($result);

        $this->assertArrayHasKey(
            'status',
            $result
        );

        $this->assertArrayHasKey(
            'sentCount',
            $result
        );

        $this->assertNotSame(
            'bootstrap_error',
            $result['status'],
            'El proceso ' . $number .
                ' no pudo iniciar Laravel.' .
                "\nRespuesta: " . $output .
                "\nErrores: " . $errors
        );

        $this->assertNotSame(
            'blocked',
            $result['status'],
            'El proceso ' . $number .
                ' no recibio el entorno esperado.'
        );

        $this->assertNotSame(
            'invalid',
            $result['status'],
            'El proceso ' . $number .
                ' recibio argumentos invalidos.'
        );

        $this->assertIsInt(
            $result['sentCount']
        );

        // Los errores del proceso se revisan junto con el
        // estado final de Firestore.
        $result['process'] = $number;
        $result['exitCode'] = $process->getExitCode();
        $result['stderr'] = $errors;

        return $result;
    }

    /**
     * Consultar el estado persistido en Firestore.
     */
    private function emailData(): array
    {
        $snapshot = $this->emailDocument->snapshot();

        $this->assertTrue(
            $snapshot->exists()
        );

        return $snapshot->data();
    }

    /**
     * Presentar los resultados cuando exista un error.
     */
    private function describeResults(array $results): string
    {
        return json_encode(
            $results,
            JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
        );
    }

    /**
     * Dos procesos no deben generar dos envios.
     */
    public function test_two_processes_do_not_duplicate_email(): void
    {
        $results = $this->runTogether();

        $description = $this->describeResults($results);

        $totalSent = array_sum(
            array_column($results, 'sentCount')
        );

        $this->assertSame(
            1,
            $totalSent,
            'Debe existir un solo envio simulado.' .
                "\nResultados:\n" . $description
        );

        $accepted = array_values(
            array_filter(
                $results,
                fn (array $result): bool =>
                    $result['status'] === 'accepted'
            )
        );

        $this->assertCount(
            1,
            $accepted,
            'Solo un proceso debe completar el envio.' .
                "\nResultados:\n" . $description
        );

        $email = $this->emailData();

        $this->assertSame(
            'accepted',
            $email['status']
        );

        $this->assertSame(
            1,
            $email['attempts']
        );

        $this->assertSame(
            $this->orderId,
            $email['orderId']
        );

        $this->assertNotNull(
            $email['processingStartedAt']
        );

        $this->assertNotNull(
            $email['acceptedAt']
        );

        $this->assertNull(
            $email['lastErrorCode']
        );
    }

    /**
     * Un correo aceptado no debe enviarse nuevamente.
     */
    public function test_accepted_email_is_not_sent_again(): void
    {
        $this->emailDocument->update([
            [
                'path' => 'status',
                'value' => 'accepted',
            ],
            [
                'path' => 'attempts',
                'value' => 1,
            ],
        ]);

        $result = $this->runOnce();

        $this->assertSame(
            'accepted',
            $result['status']
        );

        $this->assertSame(
            0,
            $result['sentCount']
        );

        $email = $this->emailData();

        $this->assertSame(
            'accepted',
            $email['status']
        );

        $this->assertSame(
            1,
            $email['attempts']
        );
    }

    /**
     * Los estados inciertos no deben reenviarse.
     */
    public function test_uncertain_email_is_not_retried(): void
    {
        $this->emailDocument->update([
            [
                'path' => 'status',
                'value' => 'uncertain',
            ],
            [
                'path' => 'attempts',
                'value' => 1,
            ],
        ]);

        $result = $this->runOnce();

        $this->assertSame(
            'uncertain',
            $result['status']
        );

        $this->assertSame(
            0,
            $result['sentCount']
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
    }

    /**
     * Los correos en procesamiento no deben reenviarse.
     */
    public function test_processing_email_is_not_retried(): void
    {
        $this->emailDocument->update([
            [
                'path' => 'status',
                'value' => 'processing',
            ],
            [
                'path' => 'attempts',
                'value' => 1,
            ],
        ]);

        $result = $this->runOnce();

        $this->assertSame(
            'processing',
            $result['status']
        );

        $this->assertSame(
            0,
            $result['sentCount']
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
    }

    /**
     * Eliminar los registros temporales del emulador.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->emailDocument)) {
                $this->emailDocument->delete();
            }

            if (isset($this->orderDocument)) {
                $this->orderDocument->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
