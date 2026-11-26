<?php

namespace App\Console\Commands;

use App\Services\ReservationItems;
use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ExpireTicketReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description =
        'Liberar boletos de reservas temporales vencidas';

    private const BATCH_SIZE = 100;

    /**
     * Buscar y liberar las reservas vencidas.
     */
    public function handle(FirestoreClient $firestore): int
    {
        $now = new DateTimeImmutable();

        $released = 0;
        $skipped = 0;
        $failed = 0;

        try {
            // Buscar solamente reservas activas y vencidas.
            $documents = $firestore
                ->collectionGroup('reservations')
                ->where('status', '=', 'active')
                ->where(
                    'expiresAt',
                    '<=',
                    new Timestamp($now)
                )
                ->limit(self::BATCH_SIZE)
                ->documents();

            foreach ($documents as $document) {
                if (!$document->exists()) {
                    continue;
                }

                try {
                    $result = $this->releaseReservation(
                        $firestore,
                        $document->reference()
                    );

                    if ($result === 'released') {
                        $released++;
                    } else {
                        $skipped++;
                    }
                } catch (Throwable $exception) {
                    $failed++;

                    Log::error(
                        'Error al liberar una reserva vencida BluePass',
                        [
                            'exception' => get_class($exception),
                        ]
                    );
                }
            }
        } catch (Throwable $exception) {
            Log::error(
                'Error al consultar reservas vencidas BluePass',
                [
                    'exception' => get_class($exception),
                ]
            );

            $this->error(
                'No fue posible consultar las reservas vencidas.'
            );

            return self::FAILURE;
        }

        $this->info('Revision de reservas finalizada.');
        $this->line('Reservas liberadas: ' . $released);
        $this->line('Reservas omitidas: ' . $skipped);
        $this->line('Reservas con errores: ' . $failed);

        if ($failed > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Liberar todos los tipos de una reserva vencida.
     */
    private function releaseReservation(
        FirestoreClient $firestore,
        DocumentReference $reservationDocument
    ): string {
        return $firestore->runTransaction(
            function (Transaction $transaction) use (
                $firestore,
                $reservationDocument
            ): string {
                // Leer la reserva dentro de la transaccion.
                $reservationSnapshot = $transaction->snapshot(
                    $reservationDocument
                );

                if (!$reservationSnapshot->exists()) {
                    return 'skipped';
                }

                $reservation = $reservationSnapshot->data();

                if (!is_array($reservation)) {
                    throw new RuntimeException(
                        'La reserva contiene datos invalidos.'
                    );
                }

                // No liberar inventario de reservas cerradas.
                if (($reservation['status'] ?? null) !== 'active') {
                    return 'skipped';
                }

                $now = new DateTimeImmutable();

                $expiresAt = $this->readTimestamp(
                    $reservation['expiresAt'] ?? null
                );

                if ($expiresAt === null) {
                    throw new RuntimeException(
                        'La reserva no tiene fecha de vencimiento valida.'
                    );
                }

                // Volver a comprobar el vencimiento.
                if ($now < $expiresAt) {
                    return 'skipped';
                }

                $eventId = $reservation['eventId'] ?? null;
                $userId = $reservation['userId'] ?? null;
                $orderId = $reservation['orderId'] ?? null;

                if (!$this->validId($eventId)) {
                    throw new RuntimeException(
                        'El evento de la reserva es invalido.'
                    );
                }

                // Aceptar reservas tradicionales y multiples.
                $items = (new ReservationItems())
                    ->fromReservation($reservation);

                $isMultiple = array_key_exists(
                    'items',
                    $reservation
                );

                $eventDocument = $firestore
                    ->collection('events')
                    ->document($eventId);

                $ticketDocuments = [];

                foreach ($items as $item) {
                    $typeId = $item['ticketTypeId'];

                    $ticketDocuments[$typeId] = $eventDocument
                        ->collection('ticketTypes')
                        ->document($typeId);
                }

                // Leer todos los inventarios antes de escribir.
                $inventoryUpdates = [];

                foreach ($items as $item) {
                    $typeId = $item['ticketTypeId'];
                    $quantity = $item['quantity'];

                    $ticketSnapshot = $transaction->snapshot(
                        $ticketDocuments[$typeId]
                    );

                    if (!$ticketSnapshot->exists()) {
                        throw new RuntimeException(
                            'No existe el inventario de la reserva.'
                        );
                    }

                    $ticket = $ticketSnapshot->data();

                    if (!is_array($ticket)) {
                        throw new RuntimeException(
                            'El inventario contiene datos invalidos.'
                        );
                    }

                    $capacity = $ticket['capacity'] ?? null;
                    $sold = $ticket['sold'] ?? null;
                    $reserved = $ticket['reserved'] ?? null;

                    if (
                        !is_int($capacity) ||
                        !is_int($sold) ||
                        !is_int($reserved) ||
                        $capacity < 0 ||
                        $sold < 0 ||
                        $reserved < $quantity ||
                        $sold > $capacity ||
                        $reserved > ($capacity - $sold)
                    ) {
                        throw new RuntimeException(
                            'El inventario es inconsistente.'
                        );
                    }

                    $inventoryUpdates[$typeId] =
                        $reserved - $quantity;
                }

                // Consultar y validar el pedido relacionado.
                $orderDocument = null;

                if ($orderId !== null) {
                    if (
                        !$this->validId($orderId) ||
                        !is_string($userId) ||
                        $userId === ''
                    ) {
                        throw new RuntimeException(
                            'La relacion entre pedido y reserva es invalida.'
                        );
                    }

                    $orderDocument = $firestore
                        ->collection('orders')
                        ->document($orderId);

                    $orderSnapshot = $transaction->snapshot(
                        $orderDocument
                    );

                    if (!$orderSnapshot->exists()) {
                        throw new RuntimeException(
                            'No se encontro el pedido asociado.'
                        );
                    }

                    $order = $orderSnapshot->data();

                    if (
                        !is_array($order) ||
                        ($order['userId'] ?? null) !== $userId ||
                        ($order['eventId'] ?? null) !== $eventId ||
                        ($order['reservationId'] ?? null) !==
                            $reservationDocument->id()
                    ) {
                        throw new RuntimeException(
                            'El pedido no coincide con la reserva.'
                        );
                    }

                    if ($isMultiple) {
                        // Comprobar categorias y cantidades del pedido.
                        $orderItems = (new ReservationItems())
                            ->fromReservation($order);

                        if ($orderItems !== $items) {
                            throw new RuntimeException(
                                'Los boletos del pedido no coinciden.'
                            );
                        }
                    } else {
                        // Conservar las comprobaciones tradicionales.
                        if (
                            ($order['ticketTypeId'] ?? null) !==
                                $items[0]['ticketTypeId'] ||
                            ($order['quantity'] ?? null) !==
                                $items[0]['quantity']
                        ) {
                            throw new RuntimeException(
                                'El pedido no coincide con la reserva.'
                            );
                        }
                    }

                    // Nunca liberar boletos de pedidos pagados.
                    if (($order['status'] ?? null) !== 'pending') {
                        throw new RuntimeException(
                            'El pedido asociado no esta pendiente.'
                        );
                    }
                }

                $timestamp = new Timestamp($now);

                // Las escrituras se realizan solo despues
                // de comprobar todas las categorias.
                foreach ($inventoryUpdates as $typeId => $reserved) {
                    $transaction->update(
                        $ticketDocuments[$typeId],
                        [
                            [
                                'path' => 'reserved',
                                'value' => $reserved,
                            ],
                            [
                                'path' => 'updatedAt',
                                'value' => $timestamp,
                            ],
                        ]
                    );
                }

                // Marcar la reserva como vencida.
                $transaction->update($reservationDocument, [
                    [
                        'path' => 'status',
                        'value' => 'expired',
                    ],
                    [
                        'path' => 'updatedAt',
                        'value' => $timestamp,
                    ],
                ]);

                // Vencer tambien el pedido asociado.
                if ($orderDocument !== null) {
                    $transaction->update($orderDocument, [
                        [
                            'path' => 'status',
                            'value' => 'expired',
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => $timestamp,
                        ],
                    ]);
                }

                return 'released';
            }
        );
    }

    /**
     * Validar identificadores de documentos.
     */
    private function validId(mixed $id): bool
    {
        return is_string($id) &&
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $id
            ) === 1;
    }

    /**
     * Leer fechas almacenadas en Firestore.
     */
    private function readTimestamp(
        mixed $value
    ): ?DateTimeImmutable {
        if ($value instanceof Timestamp) {
            $value = $value->get();
        }

        if (!$value instanceof DateTimeInterface) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value);
    }
}
