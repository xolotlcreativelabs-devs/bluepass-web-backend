<?php

namespace Tests\Feature;

use App\Services\PurchaseNotificationService;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PurchaseNotificationServiceTest extends TestCase
{
    private FirestoreClient $firestore;

    private PurchaseNotificationService $service;

    private string $orderId;

    private string $userId;

    private string $notificationId;

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutar exclusivamente con Firestore Emulator.
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

        $this->service = new PurchaseNotificationService(
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->orderId = 'pedido-notificacion-' . $suffix;
        $this->userId = 'comprador-notificacion-' . $suffix;

        $this->notificationId = hash(
            'sha256',
            'purchase_confirmed:' . $this->orderId
        );
    }

    /**
     * Consultar la notificacion de esta prueba.
     */
    private function notification()
    {
        return $this->firestore
            ->collection('notifications')
            ->document($this->notificationId);
    }

    /**
     * Registrar una notificacion mediante una transaccion.
     */
    private function createNotification(): void
    {
        $this->firestore->runTransaction(
            function ($transaction): void {
                $this->service->createConfirmedPurchase(
                    $transaction,
                    $this->orderId,
                    $this->userId,
                    [
                        'boleto-uno',
                        'boleto-dos',
                    ],
                    new Timestamp(new DateTimeImmutable())
                );
            }
        );
    }

    public function test_creates_purchase_notification(): void
    {
        $this->createNotification();

        $snapshot = $this->notification()->snapshot();

        $this->assertTrue($snapshot->exists());

        $data = $snapshot->data();

        $this->assertSame(
            $this->userId,
            $data['userId']
        );

        $this->assertSame(
            $this->orderId,
            $data['orderId']
        );

        $this->assertSame(
            'purchase_confirmed',
            $data['type']
        );

        $this->assertSame(
            'Compra confirmada',
            $data['title']
        );

        $this->assertSame(
            'unread',
            $data['status']
        );

        $this->assertSame(
            'in_app',
            $data['channel']
        );

        $this->assertSame(
            2,
            $data['ticketCount']
        );

        $this->assertNull($data['readAt']);

        $this->assertInstanceOf(
            Timestamp::class,
            $data['createdAt']
        );
    }

    public function test_failed_transaction_does_not_create_notification(): void
    {
        try {
            $this->firestore->runTransaction(
                function ($transaction): void {
                    $this->service->createConfirmedPurchase(
                        $transaction,
                        $this->orderId,
                        $this->userId,
                        ['boleto-uno'],
                        new Timestamp(new DateTimeImmutable())
                    );

                    throw new RuntimeException(
                        'Fallo simulado de la transaccion.'
                    );
                }
            );

            $this->fail(
                'La transaccion debio fallar.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Fallo simulado de la transaccion.',
                $exception->getMessage()
            );
        }

        $this->assertFalse(
            $this->notification()->snapshot()->exists()
        );
    }

    public function test_duplicate_confirmation_does_not_create_second_notification(): void
    {
        $this->createNotification();

        $before = $this->notification()
            ->snapshot()
            ->data();

        $duplicateRejected = false;

        try {
            $this->createNotification();
        } catch (Throwable $exception) {
            $duplicateRejected = true;
        }

        $this->assertTrue(
            $duplicateRejected,
            'Una segunda creacion debe ser rechazada.'
        );

        $snapshot = $this->notification()->snapshot();

        $this->assertTrue($snapshot->exists());

        $after = $snapshot->data();

        $this->assertSame(
            $before['orderId'],
            $after['orderId']
        );

        $this->assertSame(
            $before['ticketCount'],
            $after['ticketCount']
        );

        $this->assertSame(
            $before['createdAt']->get()->getTimestamp(),
            $after['createdAt']->get()->getTimestamp()
        );
    }

    public function test_rejects_empty_ticket_list(): void
    {
        try {
            $this->firestore->runTransaction(
                function ($transaction): void {
                    $this->service->createConfirmedPurchase(
                        $transaction,
                        $this->orderId,
                        $this->userId,
                        [],
                        new Timestamp(new DateTimeImmutable())
                    );
                }
            );

            $this->fail(
                'La lista vacia debe rechazarse.'
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame(
                '',
                $exception->getMessage()
            );
        }

        $this->assertFalse(
            $this->notification()->snapshot()->exists()
        );
    }

    public function test_rejects_invalid_ticket_id(): void
    {
        try {
            $this->firestore->runTransaction(
                function ($transaction): void {
                    $this->service->createConfirmedPurchase(
                        $transaction,
                        $this->orderId,
                        $this->userId,
                        ['boleto-invalido!'],
                        new Timestamp(new DateTimeImmutable())
                    );
                }
            );

            $this->fail(
                'El boleto invalido debe rechazarse.'
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame(
                '',
                $exception->getMessage()
            );
        }

        $this->assertFalse(
            $this->notification()->snapshot()->exists()
        );
    }

    /**
     * Eliminar los documentos temporales de la prueba.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore, $this->notificationId)) {
                $this->notification()->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
