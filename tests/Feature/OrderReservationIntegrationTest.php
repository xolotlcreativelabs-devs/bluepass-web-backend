<?php

namespace Tests\Feature;

use App\Services\OrderReservationValidator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Tests\TestCase;

class OrderReservationIntegrationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $eventDocument;

    private DocumentReference $reservationDocument;

    private OrderReservationValidator $validator;

    private string $eventId;

    private string $reservationId;

    protected function setUp(): void
    {
        parent::setUp();

        // Impedir conexiones a Firebase real.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'La prueba requiere Firestore Emulator y el proyecto demo-bluepass-hu04.'
            );
        }

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        $this->validator = new OrderReservationValidator();

        // Crear identificadores exclusivos de esta prueba.
        $this->eventId = 'pedido-hu05-'
            . bin2hex(random_bytes(8));

        $this->reservationId = 'reserva-hu05-'
            . bin2hex(random_bytes(8));

        $this->eventDocument = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $this->reservationDocument = $this->eventDocument
            ->collection('reservations')
            ->document($this->reservationId);

        $now = new DateTimeImmutable();

        $this->eventDocument->set([
            'name' => 'Evento de prueba de pedidos',
            'status' => 'published',
            'createdAt' => new Timestamp($now),
        ]);

        $this->reservationDocument->set([
            'eventId' => $this->eventId,
            'ticketTypeId' => 'general',
            'userId' => 'comprador-hu05',
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
        // Eliminar solo los documentos creados por esta prueba.
        if (isset($this->reservationDocument)) {
            $this->reservationDocument->delete();
        }

        if (isset($this->eventDocument)) {
            $this->eventDocument->delete();
        }

        parent::tearDown();
    }

    private function selection(): array
    {
        return [
            'reservationId' => $this->reservationId,
            'ticketTypeId' => 'general',
            'quantity' => 2,
        ];
    }

    private function validateStoredReservation(
        ?array $selection = null,
        string $userId = 'comprador-hu05'
    ): array {
        $snapshot = $this->reservationDocument->snapshot();

        $this->assertTrue(
            $snapshot->exists(),
            'La reserva de prueba debe existir en Firestore.'
        );

        return $this->validator->validate(
            $selection ?? $this->selection(),
            $snapshot->data(),
            $userId,
            $this->eventId
        );
    }

    public function test_validates_reservation_from_firestore(): void
    {
        $result = $this->validateStoredReservation();

        $this->assertTrue($result['valid']);
        $this->assertSame(200, $result['code']);
        $this->assertSame(2, $result['quantity']);
        $this->assertSame(350, $result['unitPrice']);
        $this->assertSame('MXN', $result['currency']);
    }

    public function test_rejects_different_quantity_from_firestore(): void
    {
        $selection = $this->selection();
        $selection['quantity'] = 3;

        $result = $this->validateStoredReservation(
            $selection
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    public function test_rejects_another_user_from_firestore(): void
    {
        $result = $this->validateStoredReservation(
            userId: 'otro-comprador'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(403, $result['code']);
    }

    public function test_rejects_expired_reservation_from_firestore(): void
    {
        $this->reservationDocument->update([
            [
                'path' => 'expiresAt',
                'value' => new Timestamp(
                    new DateTimeImmutable('-1 hour')
                ),
            ],
        ]);

        $result = $this->validateStoredReservation();

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }
}
