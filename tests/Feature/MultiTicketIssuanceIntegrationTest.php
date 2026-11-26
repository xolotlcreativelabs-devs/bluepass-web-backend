<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\TicketReservationController;
use App\Services\MultiTicketIssuanceService;
use App\Services\MultiTicketIssuanceValidator;
use App\Services\OrderAmountCalculator;
use App\Services\OrderReservationValidator;
use App\Services\OrderSelectionValidator;
use App\Services\PurchaseEmailOutboxService;
use App\Services\PurchaseNotificationService;
use App\Services\TicketIdGenerator;
use App\Services\TicketInventorySettlement;
use App\Services\TicketQrToken;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Tests\TestCase;

class MultiTicketIssuanceIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $eventDocument;

    private string $eventId;
    private string $userId;
    private string $orderId;
    private string $reservationId;
    private string $paymentReference;

    private array $ticketDocuments = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Impedir el uso de Firebase real.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'Estas pruebas requieren Firestore Emulator.'
            );
        }

        config()->set('bluepass.service_fee_percent', '0');

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'test-multi-issue-' . $suffix;
        $this->userId = 'buyer-multi-' . $suffix;
        $this->paymentReference = 'test-payment-' . $suffix;

        $this->eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $now = new DateTimeImmutable();

        $this->eventDocument->set([
            'name' => 'Evento de emision multiple',
            'sellerId' => 'vendedor-prueba',
            'status' => 'published',
            'date' => new Timestamp(
                $now->modify('+10 days')
            ),
            'createdAt' => new Timestamp($now),
        ]);

        foreach ([
            'general' => 50000,
            'vip' => 150000,
        ] as $typeId => $price) {
            $document = $this->eventDocument
                ->collection('ticketTypes')
                ->document($typeId);

            $document->set([
                'name' => $typeId,
                'status' => 'active',
                'price' => $price,
                'currency' => 'MXN',
                'capacity' => 10,
                'sold' => 0,
                'reserved' => 0,
                'saleStartAt' => new Timestamp(
                    $now->modify('-1 hour')
                ),
                'saleEndAt' => new Timestamp(
                    $now->modify('+5 days')
                ),
            ]);

            $this->ticketDocuments[$typeId] = $document;
        }

        $this->createReservationAndOrder($suffix);
    }

    /**
     * Preparar la seleccion de boletos.
     */
    private function items(): array
    {
        return [
            [
                'ticketTypeId' => 'vip',
                'quantity' => 2,
            ],
            [
                'ticketTypeId' => 'general',
                'quantity' => 3,
            ],
        ];
    }

    /**
     * Crear reserva y pedido mediante los controladores.
     */
    private function createReservationAndOrder(
        string $suffix
    ): void {
        $reserveRequest = Request::create(
            '/api/events/' . $this->eventId . '/reservations',
            'POST',
            [
                'items' => $this->items(),
            ]
        );

        $reserveRequest->attributes->set(
            'firebase_uid',
            $this->userId
        );

        $reserveRequest->attributes->set(
            'firebase_role',
            'customer'
        );

        $reserveResponse = (new TicketReservationController())
            ->store(
                $reserveRequest,
                $this->eventId,
                $this->firestore
            );

        $this->assertSame(
            201,
            $reserveResponse->getStatusCode(),
            $reserveResponse->getContent()
        );

        $this->reservationId = $reserveResponse
            ->getData(true)['reservation']['id'];

        $orderRequest = Request::create(
            '/api/events/' . $this->eventId . '/orders',
            'POST',
            [
                'reservationId' => $this->reservationId,
                'items' => $this->items(),
            ],
            [],
            [],
            [
                'HTTP_IDEMPOTENCY_KEY' => 'test-order-' . $suffix,
            ]
        );

        $orderRequest->attributes->set(
            'firebase_uid',
            $this->userId
        );

        $orderRequest->attributes->set(
            'firebase_role',
            'customer'
        );

        // Sin correo: estas pruebas se concentran
        // en la emision y los inventarios.
        $orderRequest->attributes->set(
            'firebase_profile',
            [
                'name' => 'Comprador de prueba',
                'email' => '',
            ]
        );

        $orderResponse = (new OrderController())->store(
            $orderRequest,
            $this->eventId,
            $this->firestore,
            new OrderSelectionValidator(),
            new OrderReservationValidator(),
            new OrderAmountCalculator()
        );

        $this->assertSame(
            201,
            $orderResponse->getStatusCode(),
            $orderResponse->getContent()
        );

        $this->orderId = $orderResponse
            ->getData(true)['order']['id'];
    }

    /**
     * Preparar el servicio real de emision multiple.
     *
     * Las notificaciones se simulan para centrar
     * estas pruebas en boletos e inventarios.
     */
    private function issuanceService(): MultiTicketIssuanceService
    {
        $notifications = $this->createMock(
            PurchaseNotificationService::class
        );

        $outbox = $this->createMock(
            PurchaseEmailOutboxService::class
        );

        return new MultiTicketIssuanceService(
            $this->firestore,
            new MultiTicketIssuanceValidator(),
            app(TicketIdGenerator::class),
            app(TicketInventorySettlement::class),
            app(TicketQrToken::class),
            $notifications,
            $outbox
        );
    }

    /**
     * Consultar el inventario de una categoria.
     */
    private function inventory(string $typeId): array
    {
        return $this->ticketDocuments[$typeId]
            ->snapshot()
            ->data();
    }

    /**
     * Consultar el pedido.
     */
    private function orderData(): array
    {
        return $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->snapshot()
            ->data();
    }

    /**
     * Consultar la reserva.
     */
    private function reservationData(): array
    {
        return $this->eventDocument
            ->collection('reservations')
            ->document($this->reservationId)
            ->snapshot()
            ->data();
    }

    /**
     * Obtener la referencia de pago simulada.
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
     * Emitir tres Generales y dos VIP.
     */
    public function test_issues_five_tickets_with_correct_categories(): void
    {
        $service = $this->issuanceService();

        $result = $service->issueForVerifiedPayment(
            $this->orderId,
            $this->paymentReference
        );

        $this->assertSame('paid', $result['status']);
        $this->assertFalse($result['alreadyIssued']);
        $this->assertCount(5, $result['ticketIds']);

        $this->assertSame(
            'paid',
            $this->orderData()['status']
        );

        $this->assertSame(
            'confirmed',
            $this->reservationData()['status']
        );

        $this->assertSame(
            3,
            $this->inventory('general')['sold']
        );

        $this->assertSame(
            0,
            $this->inventory('general')['reserved']
        );

        $this->assertSame(
            2,
            $this->inventory('vip')['sold']
        );

        $this->assertSame(
            0,
            $this->inventory('vip')['reserved']
        );

        $this->assertTrue(
            $this->paymentDocument()->snapshot()->exists()
        );

        $categories = [
            'general' => 0,
            'vip' => 0,
        ];

        foreach ($result['ticketIds'] as $index => $ticketId) {
            $snapshot = $this->firestore
                ->collection('tickets')
                ->document($ticketId)
                ->snapshot();

            $this->assertTrue($snapshot->exists());

            $ticket = $snapshot->data();

            $this->assertSame(
                $this->orderId,
                $ticket['orderId']
            );

            $this->assertSame(
                $this->userId,
                $ticket['userId']
            );

            $this->assertSame(
                $index + 1,
                $ticket['position']
            );

            $this->assertSame(
                'valid',
                $ticket['status']
            );

            $typeId = $ticket['ticketTypeId'];

            $this->assertArrayHasKey(
                $typeId,
                $categories
            );

            $categories[$typeId]++;

            $token = Crypt::decryptString(
                $ticket['qrTokenEncrypted']
            );

            $this->assertTrue(
                app(TicketQrToken::class)->verify(
                    $token,
                    $ticket['qrTokenHash']
                )
            );
        }

        $this->assertSame([
            'general' => 3,
            'vip' => 2,
        ], $categories);
    }

    /**
     * La segunda confirmacion no debe duplicar boletos.
     */
    public function test_repeated_confirmation_does_not_duplicate_tickets(): void
    {
        $service = $this->issuanceService();

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

        $this->assertCount(
            5,
            $this->orderData()['issuedTicketIds']
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
    }

    /**
     * Un inventario incorrecto impide emitir
     * cualquier boleto del pedido.
     */
    public function test_rejects_all_issuance_if_one_inventory_is_invalid(): void
    {
        // Simular que falta un boleto VIP reservado.
        $this->ticketDocuments['vip']->update([
            [
                'path' => 'reserved',
                'value' => 1,
            ],
        ]);

        try {
            $this->issuanceService()
                ->issueForVerifiedPayment(
                    $this->orderId,
                    $this->paymentReference
                );

            $this->fail(
                'La emision debio ser rechazada.'
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'No existen suficientes boletos reservados.',
                $exception->getMessage()
            );
        }

        // El pedido debe permanecer pendiente.
        $this->assertSame(
            'pending',
            $this->orderData()['status']
        );

        // La reserva no debe confirmarse.
        $this->assertSame(
            'active',
            $this->reservationData()['status']
        );

        // El inventario General no debe modificarse.
        $this->assertSame(
            0,
            $this->inventory('general')['sold']
        );

        $this->assertSame(
            3,
            $this->inventory('general')['reserved']
        );

        // El inventario VIP conserva el valor
        // alterado intencionalmente por la prueba.
        $this->assertSame(
            0,
            $this->inventory('vip')['sold']
        );

        $this->assertSame(
            1,
            $this->inventory('vip')['reserved']
        );

        // No se debe registrar ninguna referencia de pago.
        $this->assertFalse(
            $this->paymentDocument()
                ->snapshot()
                ->exists()
        );

        // No se debe generar ningun boleto.
        $ticketIds = app(TicketIdGenerator::class)
            ->generateForOrder($this->orderId, 5);

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
     * Eliminar los documentos temporales.
     */
    protected function tearDown(): void
    {
        try {
            if (
                isset(
                    $this->firestore,
                    $this->orderId
                )
            ) {
                $ticketIds = app(TicketIdGenerator::class)
                    ->generateForOrder($this->orderId, 5);

                foreach ($ticketIds as $ticketId) {
                    $this->firestore
                        ->collection('tickets')
                        ->document($ticketId)
                        ->delete();
                }

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

                foreach ($this->ticketDocuments as $document) {
                    $document->delete();
                }

                $this->eventDocument->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
