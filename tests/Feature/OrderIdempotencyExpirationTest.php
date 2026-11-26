<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderController;
use App\Services\OrderAmountCalculator;
use App\Services\OrderReservationValidator;
use App\Services\OrderSelectionValidator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderIdempotencyExpirationTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $eventId;

    private string $reservationId;

    private string $orderId;

    private string $idempotencyKey;

    private string $sellerId;

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca ejecutar esta prueba contra Firebase real.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'Esta prueba requiere Firestore Emulator.'
            );
        }

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        $this->firestore
            ->collection('users')
            ->document('comprador-seguridad')
            ->set([
                'authSubject' => 'comprador-seguridad',
                'role' => 'customer',
                'status' => 'active',
            ]);

        $suffix = bin2hex(random_bytes(8));

        $this->eventId = 'pedido-seguridad-' . $suffix;

        $this->reservationId = 'reserva-seguridad-' . $suffix;

        $this->idempotencyKey = 'clave-seguridad-' . $suffix;

        $this->sellerId = 'vendedor-seguridad-' . $suffix;

        $this->orderId = hash(
            'sha256',
            'comprador-seguridad:' . $this->idempotencyKey
        );

        $now = new DateTimeImmutable();

        $eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        // El evento debe tener un vendedor valido.
        $eventDocument->set([
            'sellerId' => $this->sellerId,
            'name' => 'Evento temporal de seguridad',
            'status' => 'published',
            'createdAt' => new Timestamp($now),
        ]);

        $eventDocument
            ->collection('ticketTypes')
            ->document('general')
            ->set([
                'name' => 'General',
                'status' => 'active',
                'price' => 350,
                'currency' => 'MXN',
                'capacity' => 10,
                'sold' => 0,
                'reserved' => 2,
            ]);

        $eventDocument
            ->collection('reservations')
            ->document($this->reservationId)
            ->set([
                'eventId' => $this->eventId,
                'ticketTypeId' => 'general',
                'userId' => 'comprador-seguridad',
                'quantity' => 2,
                'unitPrice' => 350,
                'currency' => 'MXN',
                'status' => 'active',
                'createdAt' => new Timestamp($now),
                'updatedAt' => new Timestamp($now),
                'expiresAt' => new Timestamp(
                    $now->modify('+10 minutes')
                ),
            ]);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore, $this->orderId)) {
                $this->firestore
                    ->collection('orders')
                    ->document($this->orderId)
                    ->delete();
            }

            if (isset($this->firestore, $this->eventId)) {
                $eventDocument = $this->firestore
                    ->collection('events')
                    ->document($this->eventId);

                if (isset($this->reservationId)) {
                    $eventDocument
                        ->collection('reservations')
                        ->document($this->reservationId)
                        ->delete();
                }

                $eventDocument
                    ->collection('ticketTypes')
                    ->document('general')
                    ->delete();

                $eventDocument->delete();
            }
        } finally {
            if (isset($this->firestore)) {
                $this->firestore
                    ->collection('users')
                    ->document('comprador-seguridad')
                    ->delete();
            }

            parent::tearDown();
        }
    }

    /**
     * Crear o recuperar un pedido con la misma clave.
     */
    private function sendOrderRequest(): JsonResponse
    {
        $request = Request::create(
            '/api/events/' . $this->eventId . '/orders',
            'POST',
            [
                'reservationId' => $this->reservationId,
                'ticketTypeId' => 'general',
                'quantity' => 2,
            ],
            [],
            [],
            [
                'HTTP_IDEMPOTENCY_KEY' => $this->idempotencyKey,
            ]
        );

        $request->attributes->set(
            'firebase_uid',
            'comprador-seguridad'
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        $request->attributes->set(
            'firebase_profile',
            [
                'name' => 'Comprador de seguridad',
                'email' => 'comprador@example.com',
                'role' => 'customer',
                'status' => 'active',
            ]
        );

        return (new OrderController())->store(
            $request,
            $this->eventId,
            $this->firestore,
            new OrderSelectionValidator(),
            new OrderReservationValidator(),
            new OrderAmountCalculator()
        );
    }

    public function test_rejects_replay_after_reservation_expires(): void
    {
        $first = $this->sendOrderRequest();

        $this->assertSame(201, $first->getStatusCode());

        $this->assertSame(
            $this->sellerId,
            $first->getData(true)['order']['sellerId']
        );

        // Simular el vencimiento de la reserva temporal.
        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reservations')
            ->document($this->reservationId)
            ->update([
                [
                    'path' => 'expiresAt',
                    'value' => new Timestamp(
                        new DateTimeImmutable('-1 hour')
                    ),
                ],
            ]);

        // La misma clave ya no debe devolver un pedido pagable.
        $second = $this->sendOrderRequest();

        $this->assertSame(
            409,
            $second->getStatusCode(),
            'No debe permitirse reutilizar un pedido vencido.'
        );
    }

    public function test_rejects_replay_after_reservation_cancelled(): void
    {
        $first = $this->sendOrderRequest();

        $this->assertSame(201, $first->getStatusCode());

        $this->assertSame(
            $this->sellerId,
            $first->getData(true)['order']['sellerId']
        );

        // Simular que la reserva ya fue cancelada.
        $this->firestore
            ->collection('events')
            ->document($this->eventId)
            ->collection('reservations')
            ->document($this->reservationId)
            ->update([
                [
                    'path' => 'status',
                    'value' => 'cancelled',
                ],
            ]);

        // La misma clave no debe recuperar un pedido pagable.
        $second = $this->sendOrderRequest();

        $this->assertSame(
            409,
            $second->getStatusCode(),
            'No debe permitirse reutilizar un pedido cancelado.'
        );
    }
}
