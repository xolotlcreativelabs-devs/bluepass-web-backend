<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Query;
use Google\Cloud\Firestore\QuerySnapshot;
use Google\Cloud\Firestore\Transaction;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ExpireTicketReservationsTest extends TestCase
{
    /**
     * Terminar correctamente cuando no existen reservas vencidas.
     */
    public function test_no_expired_reservations(): void
    {
        $this->mockSearch([]);

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 0')
            ->expectsOutput('Reservas con errores: 0')
            ->assertExitCode(0);
    }

    /**
     * Liberar una reserva vencida y actualizar el inventario.
     */
    public function test_releases_expired_reservation(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $reservationDocument = Mockery::mock(DocumentReference::class);

        $this->mockSearch(
            [$this->searchResult($reservationDocument)],
            $firestore
        );

        $this->mockTransaction(
            $firestore,
            $reservationDocument,
            'active',
            true,
            false
        );

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 1')
            ->expectsOutput('Reservas con errores: 0')
            ->assertExitCode(0);
    }

    /**
     * No volver a liberar una reserva ya cancelada.
     */
    public function test_skips_cancelled_reservation(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $reservationDocument = Mockery::mock(DocumentReference::class);

        $this->mockSearch(
            [$this->searchResult($reservationDocument)],
            $firestore
        );

        $this->mockTransaction(
            $firestore,
            $reservationDocument,
            'cancelled',
            false,
            false
        );

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 0')
            ->expectsOutput('Reservas omitidas: 1')
            ->assertExitCode(0);
    }

    /**
     * No liberar una reserva cuya fecha de vencimiento es futura.
     */
    public function test_skips_reservation_not_yet_expired(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $reservationDocument = Mockery::mock(DocumentReference::class);

        $this->mockSearch(
            [$this->searchResult($reservationDocument)],
            $firestore
        );

        $this->mockTransaction(
            $firestore,
            $reservationDocument,
            'active',
            false,
            true
        );

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 0')
            ->expectsOutput('Reservas omitidas: 1')
            ->assertExitCode(0);
    }

    /**
     * Rechazar inventarios que no permiten liberar la cantidad.
     */
    public function test_rejects_inconsistent_inventory(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $reservationDocument = Mockery::mock(DocumentReference::class);

        $this->mockSearch(
            [$this->searchResult($reservationDocument)],
            $firestore
        );

        $this->mockTransaction(
            $firestore,
            $reservationDocument,
            'active',
            false,
            false,
            2
        );

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 0')
            ->expectsOutput('Reservas con errores: 1')
            ->assertExitCode(1);
    }

    /**
     * Liberar 101 reservas en dos lotes sin descontar dos veces el inventario.
     */
    public function test_processes_101_expired_reservations_in_batches(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);
        $query = Mockery::mock(Query::class);
        $events = Mockery::mock(CollectionReference::class);
        $eventDocument = Mockery::mock(DocumentReference::class);
        $ticketTypes = Mockery::mock(CollectionReference::class);
        $ticketDocument = Mockery::mock(DocumentReference::class);

        $statuses = array_fill(0, 101, 'active');
        $references = [];
        $indices = [];
        $reserved = 303;
        $inventoryUpdates = 0;
        $statusUpdates = 0;
        $querySizes = [];

        for ($i = 0; $i < 101; $i++) {
            $reference = Mockery::mock(DocumentReference::class);
            $references[$i] = $reference;
            $indices[spl_object_id($reference)] = $i;
        }

        $firestore->shouldReceive('collectionGroup')
            ->times(3)
            ->with('reservations')
            ->andReturn($query);

        $query->shouldReceive('where')
            ->times(3)
            ->with('status', '=', 'active')
            ->andReturnSelf();

        $query->shouldReceive('where')
            ->times(3)
            ->with('expiresAt', '<=', Mockery::type(Timestamp::class))
            ->andReturnSelf();

        $query->shouldReceive('limit')
            ->times(3)
            ->with(100)
            ->andReturnSelf();

        $query->shouldReceive('documents')
            ->times(3)
            ->andReturnUsing(function () use (
                &$statuses,
                $references,
                &$querySizes
            ) {
                $documents = [];

                foreach ($statuses as $index => $status) {
                    if ($status !== 'active') {
                        continue;
                    }

                    $snapshot = Mockery::mock(DocumentSnapshot::class);

                    $snapshot->shouldReceive('exists')
                        ->once()
                        ->andReturn(true);

                    $snapshot->shouldReceive('reference')
                        ->once()
                        ->andReturn($references[$index]);

                    $documents[] = $snapshot;

                    if (count($documents) === 100) {
                        break;
                    }
                }

                $querySizes[] = count($documents);

                $results = Mockery::mock(QuerySnapshot::class);

                $results->shouldReceive('getIterator')
                    ->once()
                    ->andReturn(new \ArrayIterator($documents));

                return $results;
            });

        $firestore->shouldReceive('collection')
            ->times(101)
            ->with('events')
            ->andReturn($events);

        $events->shouldReceive('document')
            ->times(101)
            ->with('evento-prueba')
            ->andReturn($eventDocument);

        $eventDocument->shouldReceive('collection')
            ->times(101)
            ->with('ticketTypes')
            ->andReturn($ticketTypes);

        $ticketTypes->shouldReceive('document')
            ->times(101)
            ->with('general')
            ->andReturn($ticketDocument);

        $firestore->shouldReceive('runTransaction')
            ->times(101)
            ->andReturnUsing(function ($callback) use (
                &$statuses,
                &$reserved,
                &$inventoryUpdates,
                &$statusUpdates,
                $ticketDocument,
                $indices
            ) {
                $transaction = Mockery::mock(Transaction::class);

                $transaction->shouldReceive('snapshot')
                    ->twice()
                    ->andReturnUsing(function ($reference) use (
                        &$statuses,
                        &$reserved,
                        $ticketDocument,
                        $indices
                    ) {
                        $snapshot = Mockery::mock(DocumentSnapshot::class);

                        $snapshot->shouldReceive('exists')
                            ->once()
                            ->andReturn(true);

                        if ($reference === $ticketDocument) {
                            $snapshot->shouldReceive('data')
                                ->once()
                                ->andReturnUsing(function () use (&$reserved) {
                                    return [
                                        'capacity' => 400,
                                        'sold' => 0,
                                        'reserved' => $reserved,
                                    ];
                                });
                        } else {
                            $index = $indices[spl_object_id($reference)] ?? null;

                            $this->assertNotNull($index);

                            $snapshot->shouldReceive('data')
                                ->once()
                                ->andReturnUsing(function () use (
                                    &$statuses,
                                    $index
                                ) {
                                    return [
                                        'eventId' => 'evento-prueba',
                                        'ticketTypeId' => 'general',
                                        'quantity' => 3,
                                        'status' => $statuses[$index],
                                        'expiresAt' => new Timestamp(
                                            new DateTimeImmutable('-1 hour')
                                        ),
                                    ];
                                });
                        }

                        return $snapshot;
                    });

                $transaction->shouldReceive('update')
                    ->twice()
                    ->andReturnUsing(function ($reference, $updates) use (
                        &$statuses,
                        &$reserved,
                        &$inventoryUpdates,
                        &$statusUpdates,
                        $ticketDocument,
                        $indices
                    ) {
                        if ($reference === $ticketDocument) {
                            $this->assertTrue(
                                $this->hasUpdate(
                                    $updates,
                                    'reserved',
                                    $reserved - 3
                                )
                            );

                            $reserved -= 3;
                            $inventoryUpdates++;
                        } else {
                            $index = $indices[spl_object_id($reference)] ?? null;

                            $this->assertNotNull($index);
                            $this->assertSame('active', $statuses[$index]);

                            $this->assertTrue(
                                $this->hasUpdate(
                                    $updates,
                                    'status',
                                    'expired'
                                )
                            );

                            $statuses[$index] = 'expired';
                            $statusUpdates++;
                        }
                    });

                return $callback($transaction);
            });

        $this->app->instance(FirestoreClient::class, $firestore);

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 100')
            ->expectsOutput('Reservas omitidas: 0')
            ->expectsOutput('Reservas con errores: 0')
            ->assertExitCode(0);

        $this->assertSame(3, $reserved);
        $this->assertSame(100, $inventoryUpdates);
        $this->assertSame(100, $statusUpdates);

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 1')
            ->expectsOutput('Reservas con errores: 0')
            ->assertExitCode(0);

        $this->assertSame(0, $reserved);
        $this->assertSame(101, $inventoryUpdates);
        $this->assertSame(101, $statusUpdates);

        $this->artisan('reservations:expire')
            ->expectsOutput('Reservas liberadas: 0')
            ->expectsOutput('Reservas con errores: 0')
            ->assertExitCode(0);

        $this->assertSame([100, 1, 0], $querySizes);
        $this->assertSame(0, $reserved);
        $this->assertSame(101, $inventoryUpdates);
        $this->assertSame(101, $statusUpdates);

        $this->assertSame(
            101,
            count(array_filter(
                $statuses,
                fn (string $status): bool => $status === 'expired'
            ))
        );
    }

    /**
     * Manejar errores al consultar Firestore.
     */
    public function test_handles_firestore_query_failure(): void
    {
        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldReceive('collectionGroup')
            ->once()
            ->with('reservations')
            ->andThrow(
                new RuntimeException('Error simulado de Firestore.')
            );

        $this->app->instance(FirestoreClient::class, $firestore);

        $this->artisan('reservations:expire')
            ->expectsOutput(
                'No fue posible consultar las reservas vencidas.'
            )
            ->assertExitCode(1);
    }

    /**
     * Preparar los resultados de la consulta de reservas.
     */
    private function mockSearch(
        array $documents,
        ?FirestoreClient $firestore = null
    ): void {
        $firestore ??= Mockery::mock(FirestoreClient::class);

        $query = Mockery::mock(Query::class);
        $results = Mockery::mock(QuerySnapshot::class);

        $firestore->shouldReceive('collectionGroup')
            ->once()
            ->with('reservations')
            ->andReturn($query);

        $query->shouldReceive('where')
            ->once()
            ->with('status', '=', 'active')
            ->andReturnSelf();

        $query->shouldReceive('where')
            ->once()
            ->with(
                'expiresAt',
                '<=',
                Mockery::type(Timestamp::class)
            )
            ->andReturnSelf();

        $query->shouldReceive('limit')
            ->once()
            ->with(100)
            ->andReturnSelf();

        $query->shouldReceive('documents')
            ->once()
            ->andReturn($results);

        $results->shouldReceive('getIterator')
            ->andReturn(new \ArrayIterator($documents));

        $this->app->instance(FirestoreClient::class, $firestore);
    }

    /**
     * Preparar una reserva obtenida de la consulta.
     */
    private function searchResult(
        DocumentReference $reservationDocument
    ): DocumentSnapshot {
        $snapshot = Mockery::mock(DocumentSnapshot::class);

        $snapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $snapshot->shouldReceive('reference')
            ->once()
            ->andReturn($reservationDocument);

        return $snapshot;
    }

    /**
     * Simular la transaccion de liberacion de inventario.
     */
    private function mockTransaction(
        FirestoreClient $firestore,
        DocumentReference $reservationDocument,
        string $status,
        bool $expectRelease,
        bool $futureExpiration,
        int $reserved = 10
    ): void {
        $transaction = Mockery::mock(Transaction::class);
        $reservationSnapshot = Mockery::mock(DocumentSnapshot::class);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($reservationDocument)
            ->andReturn($reservationSnapshot);

        $reservationSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $expiresAt = (new DateTimeImmutable())->modify(
            $futureExpiration ? '+1 hour' : '-1 hour'
        );

        $reservationSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'eventId' => 'evento-prueba',
                'ticketTypeId' => 'general',
                'userId' => 'comprador-prueba',
                'quantity' => 3,
                'status' => $status,
                'expiresAt' => new Timestamp($expiresAt),
            ]);

        if ($status === 'active' && !$futureExpiration) {
            $events = Mockery::mock(CollectionReference::class);
            $eventDocument = Mockery::mock(DocumentReference::class);
            $ticketTypes = Mockery::mock(CollectionReference::class);
            $ticketDocument = Mockery::mock(DocumentReference::class);
            $ticketSnapshot = Mockery::mock(DocumentSnapshot::class);

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

            $ticketTypes->shouldReceive('document')
                ->once()
                ->with('general')
                ->andReturn($ticketDocument);

            $transaction->shouldReceive('snapshot')
                ->once()
                ->with($ticketDocument)
                ->andReturn($ticketSnapshot);

            $ticketSnapshot->shouldReceive('exists')
                ->once()
                ->andReturn(true);

            $ticketSnapshot->shouldReceive('data')
                ->once()
                ->andReturn([
                    'capacity' => 100,
                    'sold' => 25,
                    'reserved' => $reserved,
                ]);

            if ($expectRelease) {
                $transaction->shouldReceive('update')
                    ->once()
                    ->with(
                        $ticketDocument,
                        Mockery::on(function ($updates) use ($reserved) {
                            return $this->hasUpdate(
                                $updates,
                                'reserved',
                                $reserved - 3
                            );
                        })
                    );

                $transaction->shouldReceive('update')
                    ->once()
                    ->with(
                        $reservationDocument,
                        Mockery::on(function ($updates) {
                            return $this->hasUpdate(
                                $updates,
                                'status',
                                'expired'
                            );
                        })
                    );
            } else {
                $transaction->shouldNotReceive('update');
            }
        } else {
            $firestore->shouldNotReceive('collection');
            $transaction->shouldNotReceive('update');
        }

        $firestore->shouldReceive('runTransaction')
            ->once()
            ->andReturnUsing(
                function ($callback) use ($transaction) {
                    return $callback($transaction);
                }
            );
    }

    /**
     * Comprobar los datos de una actualizacion.
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
