<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\EventTicketManagementController;
use DateTimeImmutable;
use DateTimeZone;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class EventTicketManagementTest extends TestCase
{
    public function test_requires_user_identity(): void
    {
        $response = $this->callController($this->validData(), null);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_customer_cannot_create_tickets(): void
    {
        $response = $this->callController(
            $this->validData(),
            'customer'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_rejects_invalid_event_id(): void
    {
        $response = $this->callController(
            $this->validData(),
            'seller',
            'evento/invalido'
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_rejects_missing_fields(): void
    {
        $response = $this->callController([], 'seller');

        $this->assertSame(422, $response->getStatusCode());

        $errors = $response->getData(true)['errors'];

        foreach ([
            'name',
            'category',
            'price',
            'capacity',
            'saleStartAt',
            'saleEndAt',
        ] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
    }

    public function test_rejects_negative_price(): void
    {
        $data = $this->validData();
        $data['price'] = -100;

        $this->assertFieldError($data, 'price');
    }

    public function test_rejects_zero_capacity(): void
    {
        $data = $this->validData();
        $data['capacity'] = 0;

        $this->assertFieldError($data, 'capacity');
    }

    public function test_rejects_protected_fields(): void
    {
        $data = $this->validData();
        $data['sold'] = 50;
        $data['reserved'] = 20;

        $response = $this->callController($data, 'seller');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'Se enviaron campos no autorizados.',
            $response->getData(true)['message']
        );
    }

    public function test_seller_cannot_create_tickets_for_another_event(): void
    {
        $firestore = $this->mockFirestoreTransaction(
            'otro-vendedor',
            false
        );

        $response = $this->callController(
            $this->validData(),
            'seller',
            'evento-prueba',
            $firestore
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_seller_can_create_ticket_type(): void
    {
        $firestore = $this->mockFirestoreTransaction(
            'firebase-uid-prueba',
            true
        );

        $response = $this->callController(
            $this->validData(),
            'seller',
            'evento-prueba',
            $firestore
        );

        $this->assertSame(201, $response->getStatusCode());

        $ticket = $response->getData(true)['ticketType'];

        $this->assertSame('boleto-prueba', $ticket['id']);
        $this->assertSame('General', $ticket['name']);
        $this->assertSame('general', $ticket['category']);
        $this->assertSame(350, $ticket['price']);
        $this->assertSame(100, $ticket['capacity']);
        $this->assertSame(0, $ticket['sold']);
        $this->assertSame(0, $ticket['reserved']);
        $this->assertSame('active', $ticket['status']);
        $this->assertArrayHasKey('saleStartAt', $ticket);
        $this->assertArrayHasKey('saleEndAt', $ticket);
    }

    public function test_admin_can_create_ticket_type(): void
    {
        $firestore = $this->mockFirestoreTransaction(
            'otro-vendedor',
            true
        );

        $response = $this->callController(
            $this->validData(),
            'admin',
            'evento-prueba',
            $firestore
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_rejects_invalid_category(): void
    {
        $data = $this->validData();
        $data['category'] = 'desconocida';

        $this->assertFieldError($data, 'category');
    }

    public function test_rejects_decimal_price(): void
    {
        $data = $this->validData();
        $data['price'] = 350.50;

        $this->assertFieldError($data, 'price');
    }

    public function test_rejects_negative_capacity(): void
    {
        $data = $this->validData();
        $data['capacity'] = -5;

        $this->assertFieldError($data, 'capacity');
    }

    public function test_rejects_invalid_sale_date(): void
    {
        $data = $this->validData();
        $data['saleStartAt'] = 'fecha-invalida';

        $this->assertFieldError($data, 'saleStartAt');
    }

    public function test_rejects_sale_end_before_start(): void
    {
        $data = $this->validData();

        $data['saleEndAt'] = $data['saleStartAt'];

        $this->assertFieldError($data, 'saleEndAt');
    }

    public function test_rejects_expired_sale_period(): void
    {
        $timezone = new DateTimeZone('America/Mexico_City');
        $now = new DateTimeImmutable('now', $timezone);

        $data = $this->validData();
        $data['saleStartAt'] = $now
            ->modify('-5 days')
            ->format('Y-m-d\TH:i:sP');
        $data['saleEndAt'] = $now
            ->modify('-1 day')
            ->format('Y-m-d\TH:i:sP');

        $this->assertFieldError($data, 'saleEndAt');
    }

    public function test_rejects_sale_after_event_start(): void
    {
        $firestore = $this->mockFirestoreTransaction(
            'firebase-uid-prueba',
            false,
            true
        );

        $response = $this->callController(
            $this->validData(),
            'seller',
            'evento-prueba',
            $firestore
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'El cierre de venta no puede superar el inicio del evento.',
            $response->getData(true)['message']
        );
    }

    public function test_rejects_wrong_event_timezone(): void
    {
        $firestore = $this->mockFirestoreTransaction(
            'firebase-uid-prueba',
            false,
            false,
            'America/Bogota'
        );

        $response = $this->callController(
            $this->validData(),
            'seller',
            'evento-prueba',
            $firestore
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'El periodo de venta no coincide con la zona horaria del evento.',
            $response->getData(true)['message']
        );
    }

    private function assertFieldError(
        array $data,
        string $field
    ): void {
        $response = $this->callController($data, 'seller');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey(
            $field,
            $response->getData(true)['errors']
        );
    }

    private function validData(): array
    {
        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone('America/Mexico_City')
        );

        return [
            'name' => 'General',
            'description' => 'Acceso general al evento.',
            'category' => 'general',
            'price' => 350,
            'capacity' => 100,
            'saleStartAt' => $now
                ->modify('+1 day')
                ->format('Y-m-d\TH:i:sP'),
            'saleEndAt' => $now
                ->modify('+5 days')
                ->format('Y-m-d\TH:i:sP'),
        ];
    }

    private function callController(
        array $data,
        ?string $role,
        string $eventId = 'evento-prueba',
        ?FirestoreClient $firestore = null
    ): JsonResponse {
        $request = Request::create(
            '/api/events/' . $eventId . '/ticket-types',
            'POST',
            $data
        );

        if ($role !== null) {
            $request->attributes->set(
                'firebase_uid',
                'firebase-uid-prueba'
            );

            $request->attributes->set(
                'firebase_role',
                $role
            );
        }

        $firestore ??= Mockery::mock(FirestoreClient::class);

        return (new EventTicketManagementController())->store(
            $request,
            $eventId,
            $firestore
        );
    }

    private function mockFirestoreTransaction(
        string $sellerId,
        bool $expectCreate,
        bool $eventEarlier = false,
        string $timezoneName = 'America/Mexico_City'
    ): FirestoreClient {
        $firestore = Mockery::mock(FirestoreClient::class);
        $events = Mockery::mock(CollectionReference::class);
        $eventDocument = Mockery::mock(DocumentReference::class);
        $ticketTypes = Mockery::mock(CollectionReference::class);
        $ticketDocument = Mockery::mock(DocumentReference::class);
        $transaction = Mockery::mock(Transaction::class);
        $eventSnapshot = Mockery::mock(DocumentSnapshot::class);

        $eventTime = new DateTimeImmutable(
            'now',
            new DateTimeZone('America/Mexico_City')
        );

        $eventTime = $eventTime->modify(
            $eventEarlier ? '+3 days' : '+10 days'
        );

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        $events->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($eventDocument);

        $eventDocument->shouldReceive('collection')
            ->once()
            ->with('ticketTypes')
            ->andReturn($ticketTypes);

        $ticketTypes->shouldReceive('newDocument')
            ->once()
            ->andReturn($ticketDocument);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($eventDocument)
            ->andReturn($eventSnapshot);

        $eventSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $eventSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'sellerId' => $sellerId,
                'status' => 'draft',
                'timezone' => $timezoneName,
                'date' => new Timestamp($eventTime),
            ]);

        if ($expectCreate) {
            $transaction->shouldReceive('create')
                ->once()
                ->with(
                    $ticketDocument,
                    Mockery::on(function ($data) {
                        return is_array($data)
                            && ($data['name'] ?? null) === 'General'
                            && ($data['price'] ?? null) === 350
                            && ($data['capacity'] ?? null) === 100
                            && ($data['sold'] ?? null) === 0
                            && ($data['reserved'] ?? null) === 0
                            && ($data['saleStartAt'] ?? null) instanceof Timestamp
                            && ($data['saleEndAt'] ?? null) instanceof Timestamp;
                    })
                );

            $ticketDocument->shouldReceive('id')
                ->once()
                ->andReturn('boleto-prueba');
        } else {
            $transaction->shouldNotReceive('create');
        }

        $firestore->shouldReceive('runTransaction')
            ->once()
            ->andReturnUsing(function ($callback) use ($transaction) {
                return $callback($transaction);
            });

        return $firestore;
    }
}
