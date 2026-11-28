<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SupportRequestController;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class SupportRequestControllerTest extends TestCase
{
    private FirestoreClient $firestore;

    private array $documents = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca ejecutar estas pruebas fuera del emulador.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'Debes configurar Firestore Emulator antes de ejecutar estas pruebas.'
            );
        }

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                foreach ($this->documents as $document) {
                    $document->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Crear un pedido temporal del comprador indicado.
     */
    private function createOrder(string $userId): string
    {
        $id = 'support-test-order-' . bin2hex(random_bytes(8));

        $document = $this->firestore
            ->collection('orders')
            ->document($id);

        $this->documents[] = $document;

        $document->set([
            'userId' => $userId,
            'status' => 'paid',
            'total' => 10000,
            'currency' => 'MXN',
        ]);

        return $id;
    }

    /**
     * Ejecutar el controlador con una identidad de prueba.
     */
    private function createSupportRequest(
        array $data,
        ?string $uid = 'comprador-support-test',
        ?string $role = 'customer',
        string $url = '/api/support/requests'
    ): JsonResponse {
        $request = Request::create(
            $url,
            'POST',
            $data
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

        $controller = new SupportRequestController();

        $response = $controller->store(
            $request,
            $this->firestore
        );

        // Registrar documentos creados para limpiarlos.
        if ($response->getStatusCode() === 201) {
            $body = $response->getData(true);
            $id = $body['supportRequest']['id'] ?? null;

            if (is_string($id) && $id !== '') {
                $this->documents[] = $this->firestore
                    ->collection('support_requests')
                    ->document($id);
            }
        }

        return $response;
    }

    private function validData(): array
    {
        return [
            'category' => 'tickets',
            'description' => 'Tengo un problema para consultar mis boletos.',
        ];
    }

    public function test_customer_can_create_support_request(): void
    {
        $response = $this->createSupportRequest(
            $this->validData()
        );

        $this->assertSame(201, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(
            'pending',
            $body['supportRequest']['status']
        );

        $this->assertNotEmpty(
            $body['supportRequest']['id']
        );

        $id = $body['supportRequest']['id'];

        $snapshot = $this->firestore
            ->collection('support_requests')
            ->document($id)
            ->snapshot();

        $this->assertTrue($snapshot->exists());

        $saved = $snapshot->data();

        $this->assertSame(
            'comprador-support-test',
            $saved['userId']
        );

        $this->assertSame('tickets', $saved['category']);
        $this->assertSame('pending', $saved['status']);
        $this->assertNull($saved['orderId']);
        $this->assertArrayHasKey('createdAt', $saved);
        $this->assertArrayHasKey('updatedAt', $saved);
    }

    public function test_customer_can_link_own_order(): void
    {
        $orderId = $this->createOrder(
            'comprador-support-test'
        );

        $data = $this->validData();
        $data['orderId'] = $orderId;

        $response = $this->createSupportRequest($data);

        $this->assertSame(201, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(
            $orderId,
            $body['supportRequest']['orderId']
        );

        $snapshot = $this->firestore
            ->collection('support_requests')
            ->document($body['supportRequest']['id'])
            ->snapshot();

        $this->assertSame(
            $orderId,
            $snapshot->data()['orderId']
        );
    }

    public function test_customer_cannot_link_another_users_order(): void
    {
        $orderId = $this->createOrder(
            'otro-comprador-support-test'
        );

        $data = $this->validData();
        $data['orderId'] = $orderId;

        $response = $this->createSupportRequest($data);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertArrayNotHasKey(
            'supportRequest',
            $response->getData(true)
        );
    }

    public function test_missing_order_is_rejected(): void
    {
        $data = $this->validData();
        $data['orderId'] = 'pedido-inexistente-' .
            bin2hex(random_bytes(8));

        $response = $this->createSupportRequest($data);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_invalid_category_is_rejected(): void
    {
        $data = $this->validData();
        $data['category'] = 'categoria-invalida';

        $response = $this->createSupportRequest($data);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_short_description_is_rejected(): void
    {
        $data = $this->validData();
        $data['description'] = 'Problema';

        $response = $this->createSupportRequest($data);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_blank_description_is_rejected(): void
    {
        $data = $this->validData();
        $data['description'] = str_repeat(' ', 20);

        $response = $this->createSupportRequest($data);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_protected_fields_are_rejected(): void
    {
        $data = $this->validData();
        $data['userId'] = 'otro-comprador';

        $response = $this->createSupportRequest($data);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_invalid_order_id_is_rejected(): void
    {
        $data = $this->validData();
        $data['orderId'] = '../otro-pedido';

        $response = $this->createSupportRequest($data);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_missing_identity_is_rejected(): void
    {
        $response = $this->createSupportRequest(
            $this->validData(),
            null,
            null
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_seller_cannot_create_support_request(): void
    {
        $response = $this->createSupportRequest(
            $this->validData(),
            'vendedor-support-test',
            'seller'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_admin_cannot_create_customer_request(): void
    {
        $response = $this->createSupportRequest(
            $this->validData(),
            'admin-support-test',
            'admin'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_query_parameters_are_rejected(): void
    {
        $response = $this->createSupportRequest(
            $this->validData(),
            'comprador-support-test',
            'customer',
            '/api/support/requests?userId=otro-comprador'
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_each_request_receives_unique_identifier(): void
    {
        $first = $this->createSupportRequest(
            $this->validData()
        );

        $second = $this->createSupportRequest(
            $this->validData()
        );

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(201, $second->getStatusCode());

        $firstId = $first->getData(true)['supportRequest']['id'];
        $secondId = $second->getData(true)['supportRequest']['id'];

        $this->assertNotSame($firstId, $secondId);
    }
}
