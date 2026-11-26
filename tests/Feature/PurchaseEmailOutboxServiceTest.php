<?php

namespace Tests\Feature;

use App\Services\PurchaseEmailOutboxService;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PurchaseEmailOutboxServiceTest extends TestCase
{
    private FirestoreClient $firestore;

    private PurchaseEmailOutboxService $service;

    private string $orderId;

    private string $userId;

    private string $emailId;

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

        $this->service = new PurchaseEmailOutboxService(
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->orderId = 'order-email-' . $suffix;
        $this->userId = 'buyer-email-' . $suffix;

        $this->emailId = hash(
            'sha256',
            'purchase_confirmation:' . $this->orderId
        );

        Mail::fake();
    }

    /**
     * Registrar un correo dentro de una transaccion.
     */
    private function createPending(
        string $buyerEmail = 'comprador@example.com',
        array $ticketIds = [
            'ticket-001',
            'ticket-002',
        ],
        string $buyerName = 'Comprador de prueba',
        ?string $orderId = null,
        ?string $userId = null
    ): string {
        return $this->firestore->runTransaction(
            function (Transaction $transaction) use (
                $buyerEmail,
                $ticketIds,
                $buyerName,
                $orderId,
                $userId
            ): string {
                return $this->service->createPending(
                    $transaction,
                    $orderId ?? $this->orderId,
                    $userId ?? $this->userId,
                    $buyerName,
                    $buyerEmail,
                    $ticketIds,
                    new Timestamp(
                        new \DateTimeImmutable()
                    )
                );
            }
        );
    }

    /**
     * Obtener el documento utilizado en las pruebas.
     */
    private function outboxDocument()
    {
        return $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document($this->emailId);
    }

    /**
     * Debe guardar el correo con estado pendiente.
     */
    public function test_creates_pending_email_record(): void
    {
        $result = $this->createPending();

        $this->assertSame($this->emailId, $result);

        $snapshot = $this->outboxDocument()->snapshot();

        $this->assertTrue($snapshot->exists());

        $data = $snapshot->data();

        $this->assertSame(
            $this->orderId,
            $data['orderId']
        );

        $this->assertSame(
            $this->userId,
            $data['userId']
        );

        $this->assertSame(
            'purchase_confirmation',
            $data['type']
        );

        $this->assertSame(
            'comprador@example.com',
            $data['recipientEmail']
        );

        $this->assertSame(
            'Comprador de prueba',
            $data['recipientName']
        );

        $this->assertSame(2, $data['ticketCount']);
        $this->assertSame('pending', $data['status']);
        $this->assertSame(0, $data['attempts']);

        $this->assertNull($data['processingStartedAt']);
        $this->assertNull($data['acceptedAt']);
        $this->assertNull($data['lastErrorCode']);

        $this->assertInstanceOf(
            Timestamp::class,
            $data['createdAt']
        );

        $this->assertInstanceOf(
            Timestamp::class,
            $data['updatedAt']
        );

        Mail::assertNothingSent();
    }

    /**
     * Un pedido no debe generar dos registros.
     */
    public function test_rejects_duplicate_email_record(): void
    {
        $this->createPending();

        $original = $this->outboxDocument()
            ->snapshot()
            ->data();

        $duplicateRejected = false;

        try {
            $this->createPending();
        } catch (Throwable $exception) {
            $duplicateRejected = true;
        }

        $this->assertTrue(
            $duplicateRejected,
            'Se esperaba rechazar el registro duplicado.'
        );

        $snapshot = $this->outboxDocument()->snapshot();

        $this->assertTrue($snapshot->exists());

        $saved = $snapshot->data();

        $this->assertSame('pending', $saved['status']);

        $this->assertSame(
            0,
            $saved['attempts']
        );

        $this->assertSame(
            $original['recipientEmail'],
            $saved['recipientEmail']
        );

        Mail::assertNothingSent();
    }

    /**
     * No aceptar correos electronicos invalidos.
     */
    public function test_rejects_invalid_buyer_email(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->createPending('correo-invalido');
    }

    /**
     * No permitir pedidos sin boletos.
     */
    public function test_rejects_order_without_tickets(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->createPending(
            'comprador@example.com',
            []
        );
    }

    /**
     * No permitir identificadores duplicados de boletos.
     */
    public function test_rejects_duplicated_tickets(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->createPending(
            'comprador@example.com',
            [
                'ticket-001',
                'ticket-001',
            ]
        );
    }

    /**
     * No permitir identificadores de pedido invalidos.
     */
    public function test_rejects_invalid_order_id(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->createPending(
            'comprador@example.com',
            ['ticket-001'],
            'Comprador de prueba',
            'pedido/con/ruta'
        );
    }

    /**
     * No permitir identificadores de comprador invalidos.
     */
    public function test_rejects_invalid_user_id(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->createPending(
            'comprador@example.com',
            ['ticket-001'],
            'Comprador de prueba',
            null,
            'comprador/con/ruta'
        );
    }

    /**
     * Una transaccion fallida no debe guardar el correo.
     */
    public function test_failed_transaction_does_not_create_email(): void
    {
        try {
            $this->firestore->runTransaction(
                function (Transaction $transaction): void {
                    $this->service->createPending(
                        $transaction,
                        $this->orderId,
                        $this->userId,
                        'Comprador de prueba',
                        'comprador@example.com',
                        ['ticket-001'],
                        new Timestamp(
                            new \DateTimeImmutable()
                        )
                    );

                    throw new RuntimeException(
                        'Cancelar transaccion de prueba.'
                    );
                }
            );

            $this->fail(
                'La transaccion debio fallar.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Cancelar transaccion de prueba.',
                $exception->getMessage()
            );
        }

        $this->assertFalse(
            $this->outboxDocument()
                ->snapshot()
                ->exists()
        );

        Mail::assertNothingSent();
    }

    /**
     * Registrar un correo pendiente no debe enviarlo.
     */
    public function test_creating_record_does_not_send_mail(): void
    {
        $this->createPending();

        Mail::assertNothingSent();

        $this->assertSame(
            'pending',
            $this->outboxDocument()
                ->snapshot()
                ->data()['status']
        );
    }

    /**
     * Eliminar los documentos temporales del emulador.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore, $this->emailId)) {
                $this->outboxDocument()->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
