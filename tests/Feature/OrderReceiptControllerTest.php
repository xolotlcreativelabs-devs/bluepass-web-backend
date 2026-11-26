<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderReceiptController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderReceiptControllerTest extends TestCase
{
    private FirestoreClient $firestore;

    private OrderReceiptController $controller;

    private string $buyerId;

    private string $otherBuyerId;

    private array $orderIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutar solamente con Firestore Emulator.
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

        $this->controller = new OrderReceiptController();

        $suffix = bin2hex(random_bytes(8));

        $this->buyerId = 'comprador-recibo-' . $suffix;
        $this->otherBuyerId = 'otro-comprador-' . $suffix;

        foreach (
            [
                'paid',
                'other',
                'pending',
                'cancelled',
                'invalid',
                'no-date',
            ] as $name
        ) {
            $this->orderIds[$name] = 'recibo-' . $name . '-' . $suffix;
        }

        $this->saveOrder(
            'paid',
            $this->orderData($this->buyerId)
        );

        $this->saveOrder(
            'other',
            $this->orderData($this->otherBuyerId)
        );

        $pending = $this->orderData($this->buyerId);
        $pending['status'] = 'pending';
        unset($pending['paidAt']);

        $this->saveOrder('pending', $pending);

        $cancelled = $this->orderData($this->buyerId);
        $cancelled['status'] = 'cancelled';
        unset($cancelled['paidAt']);

        $this->saveOrder('cancelled', $cancelled);

        $invalid = $this->orderData($this->buyerId);
        $invalid['total'] = 900;

        $this->saveOrder('invalid', $invalid);

        $withoutDate = $this->orderData($this->buyerId);
        unset($withoutDate['paidAt']);

        $this->saveOrder('no-date', $withoutDate);
    }

    /**
     * Preparar los datos de un pedido pagado.
     */
    private function orderData(string $userId): array
    {
        $now = new Timestamp(new DateTimeImmutable());

        return [
            'userId' => $userId,
            'eventId' => 'evento-recibo-prueba',
            'sellerId' => 'vendedor-recibo-prueba',
            'channel' => 'online',
            'items' => [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 2,
                    'unitPrice' => 350,
                    'subtotal' => 700,
                ],
            ],
            'reservationId' => 'reserva-recibo-prueba',
            'ticketTypeId' => 'general',
            'quantity' => 2,
            'unitPrice' => 350,
            'currency' => 'MXN',
            'subtotal' => 700,
            'fees' => 0,
            'total' => 700,
            'status' => 'paid',
            'buyer' => [
                'id' => $userId,
                'name' => 'Comprador BluePass',
                'email' => 'comprador@example.com',
            ],
            'idempotencyKey' => 'clave-interna-prueba',
            'paymentReference' => 'pago-interno-prueba',
            'createdAt' => $now,
            'updatedAt' => $now,
            'paidAt' => $now,
        ];
    }

    /**
     * Guardar un pedido temporal.
     */
    private function saveOrder(string $name, array $data): void
    {
        $this->firestore
            ->collection('orders')
            ->document($this->orderIds[$name])
            ->set($data);
    }

    /**
     * Preparar una solicitud con identidad simulada.
     */
    private function customerRequest(
        ?string $uid,
        ?string $role = 'customer',
        array $query = []
    ): Request {
        $request = Request::create(
            '/api/orders/receipt',
            'GET',
            $query
        );

        if ($uid !== null) {
            $request->attributes->set(
                'firebase_uid',
                $uid
            );
        }

        if ($role !== null) {
            $request->attributes->set(
                'firebase_role',
                $role
            );
        }

        return $request;
    }

    /**
     * Consultar un comprobante desde el controlador.
     */
    private function receipt(
        string $name,
        ?string $uid = null,
        ?string $role = 'customer',
        array $query = []
    ): \Illuminate\Http\JsonResponse {
        return $this->controller->show(
            $this->customerRequest(
                $uid ?? $this->buyerId,
                $role,
                $query
            ),
            $this->orderIds[$name],
            $this->firestore
        );
    }

    public function test_customer_can_view_paid_receipt(): void
    {
        $response = $this->receipt('paid');

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(
            $this->orderIds['paid'],
            $body['receipt']['orderId']
        );

        $this->assertSame(
            'paid',
            $body['receipt']['status']
        );

        $this->assertSame(
            'MXN',
            $body['receipt']['currency']
        );

        $this->assertNotEmpty(
            $body['receipt']['paidAt']
        );
    }

    public function test_receipt_contains_saved_amounts(): void
    {
        $response = $this->receipt('paid');

        $this->assertSame(200, $response->getStatusCode());

        $receipt = $response->getData(true)['receipt'];

        $this->assertSame(2, $receipt['quantity']);
        $this->assertSame(700, $receipt['subtotal']);
        $this->assertSame(0, $receipt['fees']);
        $this->assertSame(700, $receipt['total']);

        $this->assertSame(
            [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 2,
                    'unitPrice' => 350,
                    'subtotal' => 700,
                ],
            ],
            $receipt['items']
        );
    }

    public function test_receipt_contains_buyer_information(): void
    {
        $response = $this->receipt('paid');

        $this->assertSame(200, $response->getStatusCode());

        $receipt = $response->getData(true)['receipt'];

        $this->assertSame(
            'Comprador BluePass',
            $receipt['buyer']['name']
        );

        $this->assertSame(
            'comprador@example.com',
            $receipt['buyer']['email']
        );

        $this->assertSame(
            'evento-recibo-prueba',
            $receipt['eventId']
        );
    }

    public function test_receipt_does_not_expose_internal_fields(): void
    {
        $response = $this->receipt('paid');

        $this->assertSame(200, $response->getStatusCode());

        $receipt = $response->getData(true)['receipt'];

        foreach (
            [
                'idempotencyKey',
                'paymentReference',
                'reservationId',
                'qrTokenHash',
                'qrTokenEncrypted',
                'qrValue',
                'userId',
            ] as $field
        ) {
            $this->assertArrayNotHasKey(
                $field,
                $receipt
            );
        }
    }

    public function test_customer_cannot_view_another_receipt(): void
    {
        $response = $this->receipt('other');

        $this->assertSame(404, $response->getStatusCode());

        $this->assertArrayNotHasKey(
            'receipt',
            $response->getData(true)
        );
    }

    public function test_pending_order_has_no_receipt(): void
    {
        $response = $this->receipt('pending');

        $this->assertSame(409, $response->getStatusCode());

        $this->assertArrayNotHasKey(
            'receipt',
            $response->getData(true)
        );
    }

    public function test_cancelled_order_has_no_receipt(): void
    {
        $response = $this->receipt('cancelled');

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_inconsistent_amounts_are_rejected(): void
    {
        $response = $this->receipt('invalid');

        $this->assertSame(409, $response->getStatusCode());

        $this->assertArrayNotHasKey(
            'receipt',
            $response->getData(true)
        );
    }

    public function test_paid_order_requires_payment_date(): void
    {
        $response = $this->receipt('no-date');

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_missing_order_returns_404(): void
    {
        $response = $this->controller->show(
            $this->customerRequest($this->buyerId),
            'pedido-inexistente-' . bin2hex(random_bytes(8)),
            $this->firestore
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_invalid_order_id_returns_404(): void
    {
        $response = $this->controller->show(
            $this->customerRequest($this->buyerId),
            'pedido-invalido!',
            $this->firestore
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_receipt_requires_authentication(): void
    {
        $request = $this->customerRequest(null, null);

        $response = $this->controller->show(
            $request,
            $this->orderIds['paid'],
            $this->firestore
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_seller_cannot_view_receipt(): void
    {
        $response = $this->receipt(
            'paid',
            $this->buyerId,
            'seller'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_receipt_rejects_unexpected_parameters(): void
    {
        $response = $this->receipt(
            'paid',
            $this->buyerId,
            'customer',
            ['total' => 1]
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_receipt_disables_cache(): void
    {
        $response = $this->receipt('paid');

        $this->assertSame(200, $response->getStatusCode());

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', '')
        );

        $this->assertSame(
            'nosniff',
            $response->headers->get(
                'X-Content-Type-Options'
            )
        );
    }

    /**
     * Eliminar los pedidos creados durante la prueba.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                foreach ($this->orderIds as $orderId) {
                    $this->firestore
                        ->collection('orders')
                        ->document($orderId)
                        ->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
