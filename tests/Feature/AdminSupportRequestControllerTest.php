<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AdminSupportRequestController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminSupportRequestControllerTest extends TestCase
{
    private FirestoreClient $firestore;

    private array $documents = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutar exclusivamente con Firestore Emulator.
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
     * Crear una incidencia temporal en el emulador.
     */
    private function createSupportRequest(
        array $changes = []
    ): string {
        $id = 'admin-support-test-' .
            bin2hex(random_bytes(8));

        $document = $this->firestore
            ->collection('support_requests')
            ->document($id);

        $this->documents[] = $document;

        $now = new Timestamp(new DateTimeImmutable());

        $data = array_merge([
            'userId' => 'comprador-support-test',
            'category' => 'tickets',
            'description' => 'Tengo problemas para consultar mis boletos.',
            'orderId' => null,
            'status' => 'pending',
            'createdAt' => $now,
            'updatedAt' => $now,
        ], $changes);

        $document->set($data);

        return $id;
    }

    /**
     * Consultar el listado con una identidad de prueba.
     */
    private function listRequests(
        ?string $uid = 'admin-support-test',
        ?string $role = 'admin',
        string $url = '/api/admin/support/requests'
    ): JsonResponse {
        $request = Request::create($url, 'GET');

        if ($uid !== null) {
            $request->attributes->set('firebase_uid', $uid);
        }

        if ($role !== null) {
            $request->attributes->set('firebase_role', $role);
        }

        return (new AdminSupportRequestController())->index(
            $request,
            $this->firestore
        );
    }

    /**
     * Consultar un detalle con una identidad de prueba.
     */
    private function showRequest(
        string $id,
        ?string $uid = 'admin-support-test',
        ?string $role = 'admin',
        string $query = ''
    ): JsonResponse {
        $url = '/api/admin/support/requests/' . $id . $query;

        $request = Request::create($url, 'GET');

        if ($uid !== null) {
            $request->attributes->set('firebase_uid', $uid);
        }

        if ($role !== null) {
            $request->attributes->set('firebase_role', $role);
        }

        return (new AdminSupportRequestController())->show(
            $request,
            $id,
            $this->firestore
        );
    }

    public function test_admin_can_list_support_requests(): void
    {
        $id = $this->createSupportRequest();

        $response = $this->listRequests();

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertArrayHasKey('requests', $body);
        $this->assertArrayHasKey('count', $body);
        $this->assertIsArray($body['requests']);

        $ids = array_column($body['requests'], 'id');

        $this->assertContains($id, $ids);
    }

    public function test_admin_can_view_request_details(): void
    {
        $id = $this->createSupportRequest([
            'category' => 'payments',
            'orderId' => 'pedido-support-test',
        ]);

        $response = $this->showRequest($id);

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(
            $id,
            $body['supportRequest']['id']
        );

        $this->assertSame(
            'comprador-support-test',
            $body['supportRequest']['userId']
        );

        $this->assertSame(
            'payments',
            $body['supportRequest']['category']
        );

        $this->assertSame(
            'pedido-support-test',
            $body['supportRequest']['orderId']
        );

        $this->assertSame(
            'pending',
            $body['supportRequest']['status']
        );

        $this->assertNotNull(
            $body['supportRequest']['createdAt']
        );
    }

    public function test_admin_can_limit_results(): void
    {
        $response = $this->listRequests(
            'admin-support-test',
            'admin',
            '/api/admin/support/requests?limit=1'
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertLessThanOrEqual(
            1,
            count($body['requests'])
        );
    }

    public function test_invalid_limit_is_rejected(): void
    {
        $response = $this->listRequests(
            'admin-support-test',
            'admin',
            '/api/admin/support/requests?limit=100'
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_unexpected_query_parameters_are_rejected(): void
    {
        $response = $this->listRequests(
            'admin-support-test',
            'admin',
            '/api/admin/support/requests?userId=otro-comprador'
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_missing_identity_cannot_list_requests(): void
    {
        $response = $this->listRequests(null, null);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_customer_cannot_list_requests(): void
    {
        $response = $this->listRequests(
            'comprador-support-test',
            'customer'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_seller_cannot_list_requests(): void
    {
        $response = $this->listRequests(
            'vendedor-support-test',
            'seller'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_customer_cannot_view_request_details(): void
    {
        $id = $this->createSupportRequest();

        $response = $this->showRequest(
            $id,
            'comprador-support-test',
            'customer'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_seller_cannot_view_request_details(): void
    {
        $id = $this->createSupportRequest();

        $response = $this->showRequest(
            $id,
            'vendedor-support-test',
            'seller'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_missing_identity_cannot_view_details(): void
    {
        $id = 'solicitud-inexistente';

        $response = $this->showRequest(
            $id,
            null,
            null
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_nonexistent_request_returns_404(): void
    {
        $id = 'solicitud-inexistente-' .
            bin2hex(random_bytes(8));

        $response = $this->showRequest($id);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_invalid_request_id_is_rejected(): void
    {
        $response = $this->showRequest('id.invalido');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_inconsistent_request_is_not_exposed(): void
    {
        $id = $this->createSupportRequest([
            'userId' => '',
        ]);

        $response = $this->showRequest($id);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_detail_rejects_query_parameters(): void
    {
        $id = $this->createSupportRequest();

        $response = $this->showRequest(
            $id,
            'admin-support-test',
            'admin',
            '?userId=otro-comprador'
        );

        $this->assertSame(422, $response->getStatusCode());
    }
}
