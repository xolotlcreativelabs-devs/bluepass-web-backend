<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TicketReservationController;
use DateTimeImmutable;
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

class TicketReservationConcurrencyTest extends TestCase
{
    private string $currentUserId = '';
    /**
     * Impedir que dos compradores reserven el mismo ultimo boleto.
     */
    public function test_two_buyers_cannot_reserve_the_last_ticket(): void
    {
        $reserved = 0;

        $references = $this->prepareFirestore(2);
        $firestore = $references['firestore'];

        $firestore->shouldReceive('runTransaction')
            ->twice()
            ->andReturnUsing(function ($callback) use (
                $references,
                &$reserved
            ) {
                $transaction = $this->mockTransaction(
                    $references,
                    $reserved,
                    true
                );

                return $callback($transaction);
            });

        $firstResponse = $this->reserve(
            $firestore,
            'comprador-uno'
        );

        $this->assertSame(
            201,
            $firstResponse->getStatusCode()
        );

        $this->assertSame(
            1,
            $firstResponse->getData(true)['inventory']['reserved']
        );

        $this->assertSame(1, $reserved);

        $secondResponse = $this->reserve(
            $firestore,
            'comprador-dos'
        );

        $this->assertSame(
            409,
            $secondResponse->getStatusCode()
        );

        $secondResult = $secondResponse->getData(true);

        $this->assertSame(
            'No hay suficientes boletos disponibles.',
            $secondResult['message']
        );

        $this->assertSame(
            0,
            $secondResult['available']
        );

        $this->assertSame(1, $reserved);
    }

    /**
     * Revalidar el inventario cuando una transaccion se reintenta.
     */
    public function test_retry_rejects_ticket_reserved_by_another_buyer(): void
    {
        $reserved = 0;
        $attempts = 0;

        $references = $this->prepareFirestore(1);
        $firestore = $references['firestore'];

        $firestore->shouldReceive('runTransaction')
            ->once()
            ->andReturnUsing(function ($callback) use (
                $references,
                &$reserved,
                &$attempts
            ) {
                // Primer intento: el boleto aparece disponible.
                $attempts++;

                $firstTransaction = $this->mockTransaction(
                    $references,
                    $reserved,
                    false
                );

                $firstResult = $callback($firstTransaction);

                $this->assertSame(201, $firstResult['code']);

                // Simular que otra transaccion confirmo la reserva.
                // Las escrituras del primer intento no se aplican.
                $reserved = 1;

                // Segundo intento: volver a leer el inventario.
                $attempts++;

                $secondTransaction = $this->mockTransaction(
                    $references,
                    $reserved,
                    false
                );

                return $callback($secondTransaction);
            });

        $response = $this->reserve(
            $firestore,
            'comprador-dos'
        );

        $this->assertSame(409, $response->getStatusCode());

        $result = $response->getData(true);

        $this->assertSame(
            'No hay suficientes boletos disponibles.',
            $result['message']
        );

        $this->assertSame(0, $result['available']);
        $this->assertSame(2, $attempts);
        $this->assertSame(1, $reserved);
    }

    /**
     * Preparar las referencias de Firestore.
     */
    private function prepareFirestore(int $requests): array
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $users = Mockery::mock(CollectionReference::class);
        $userDocument = Mockery::mock(DocumentReference::class);

        $events = Mockery::mock(CollectionReference::class);
        $eventDocument = Mockery::mock(DocumentReference::class);
        $ticketTypes = Mockery::mock(CollectionReference::class);
        $ticketDocument = Mockery::mock(DocumentReference::class);
        $reservations = Mockery::mock(CollectionReference::class);

        $firestore->shouldReceive('collection')
            ->times($requests)
            ->with('users')
            ->andReturn($users);

        $users->shouldReceive('document')
            ->times($requests)
            ->with(Mockery::type('string'))
            ->andReturn($userDocument);

        $firestore->shouldReceive('collection')
            ->times($requests)
            ->with('events')
            ->andReturn($events);

        $events->shouldReceive('document')
            ->times($requests)
            ->with('evento-prueba')
            ->andReturn($eventDocument);

        $eventDocument->shouldReceive('collection')
            ->times($requests)
            ->with('ticketTypes')
            ->andReturn($ticketTypes);

        $ticketTypes->shouldReceive('document')
            ->times($requests)
            ->with('general')
            ->andReturn($ticketDocument);

        $eventDocument->shouldReceive('collection')
            ->times($requests)
            ->with('reservations')
            ->andReturn($reservations);

        $reservationCounter = 0;

        $reservations->shouldReceive('newDocument')
            ->times($requests)
            ->andReturnUsing(function () use (
                &$reservationCounter
            ) {
                $reservationCounter++;

                $document = Mockery::mock(
                    DocumentReference::class
                );

                $document->shouldReceive('id')
                    ->zeroOrMoreTimes()
                    ->andReturn(
                        'reserva-' . $reservationCounter
                    );

                return $document;
            });

        return [
            'firestore' => $firestore,
            'eventDocument' => $eventDocument,
            'userDocument' => $userDocument,
            'ticketDocument' => $ticketDocument,
        ];
    }

    /**
     * Simular una transaccion con inventario compartido.
     */
    private function mockTransaction(
        array $references,
        int &$reserved,
        bool $applyWrites
    ): Transaction {
        $transaction = Mockery::mock(Transaction::class);

        $userSnapshot = Mockery::mock(DocumentSnapshot::class);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($references['userDocument'])
            ->andReturn($userSnapshot);

        $userSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $userSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'authSubject' => $this->currentUserId,
                'role' => 'customer',
                'status' => 'active',
            ]);

        $eventSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $ticketSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $now = new DateTimeImmutable();

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($references['eventDocument'])
            ->andReturn($eventSnapshot);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($references['ticketDocument'])
            ->andReturn($ticketSnapshot);

        $eventSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $eventSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'status' => 'published',
                'date' => new Timestamp(
                    $now->modify('+10 days')
                ),
            ]);

        $ticketSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $ticketSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'name' => 'General',
                'status' => 'active',
                'price' => 350,
                'capacity' => 1,
                'sold' => 0,
                'reserved' => $reserved,
                'saleStartAt' => new Timestamp(
                    $now->modify('-1 hour')
                ),
                'saleEndAt' => new Timestamp(
                    $now->modify('+5 days')
                ),
            ]);

        if ($reserved === 0) {
            $transaction->shouldReceive('create')
                ->once()
                ->with(
                    Mockery::type(DocumentReference::class),
                    Mockery::on(function ($data) {
                        return is_array($data)
                            && ($data['eventId'] ?? null)
                                === 'evento-prueba'
                            && ($data['ticketTypeId'] ?? null)
                                === 'general'
                            && ($data['quantity'] ?? null) === 1
                            && ($data['status'] ?? null)
                                === 'active'
                            && ($data['expiresAt'] ?? null)
                                instanceof Timestamp;
                    })
                );

            $transaction->shouldReceive('update')
                ->once()
                ->with(
                    $references['ticketDocument'],
                    Mockery::on(function ($updates) {
                        return $this->hasUpdate(
                            $updates,
                            'reserved',
                            1
                        );
                    })
                )
                ->andReturnUsing(function () use (
                    &$reserved,
                    $applyWrites
                ) {
                    if ($applyWrites) {
                        $reserved = 1;
                    }
                });
        } else {
            $transaction->shouldNotReceive('create');
            $transaction->shouldNotReceive('update');
        }

        return $transaction;
    }

    /**
     * Simular una solicitud de reserva del comprador.
     */
    private function reserve(
        FirestoreClient $firestore,
        string $uid
    ): JsonResponse {
        $request = Request::create(
            '/api/events/evento-prueba/reservations',
            'POST',
            [
                'ticketTypeId' => 'general',
                'quantity' => 1,
            ]
        );

        $request->attributes->set(
            'firebase_uid',
            $uid
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        $this->currentUserId = $uid;

        $controller = new TicketReservationController();

        return $controller->store(
            $request,
            'evento-prueba',
            $firestore
        );
    }

    /**
     * Comprobar el valor de una actualizacion.
     */
    private function hasUpdate(
        mixed $updates,
        string $path,
        mixed $value
    ): bool {
        if (!is_array($updates)) {
            return false;
        }

        foreach ($updates as $update) {
            if (
                is_array($update) &&
                ($update['path'] ?? null) === $path &&
                ($update['value'] ?? null) === $value
            ) {
                return true;
            }
        }

        return false;
    }
}
