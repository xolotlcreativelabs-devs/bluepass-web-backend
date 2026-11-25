<?php

namespace App\Console\Commands;

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

    protected $description = 'Liberar boletos de reservas temporales vencidas';

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
     * Liberar una reserva y vencer su pedido asociado.
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
                // Consultar la reserva dentro de la transaccion.
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

                // No procesar reservas ya cerradas.
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

                // Revisar el vencimiento nuevamente.
                if ($now < $expiresAt) {
                    return 'skipped';
                }

                $eventId = $reservation['eventId'] ?? null;
                $ticketTypeId = $reservation['ticketTypeId'] ?? null;
                $quantity = $reservation['quantity'] ?? null;
                $userId = $reservation['userId'] ?? null;
                $orderId = $reservation['orderId'] ?? null;

                if (
                    !$this->validId($eventId) ||
                    !$this->validId($ticketTypeId) ||
                    !is_int($quantity) ||
                    $quantity < 1
                ) {
                    throw new RuntimeException(
                        'La reserva contiene datos de inventario invalidos.'
                    );
                }

                $ticketDocument = $firestore
                    ->collection('events')
                    ->document($eventId)
                    ->collection('ticketTypes')
                    ->document($ticketTypeId);

                // Leer el inventario antes de escribir.
                $ticketSnapshot = $transaction->snapshot(
                    $ticketDocument
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

                // Comprobar si existe un pedido relacionado.
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
                        ($order['ticketTypeId'] ?? null) !== $ticketTypeId ||
                        ($order['quantity'] ?? null) !== $quantity ||
                        ($order['reservationId'] ?? null) !==
                            $reservationDocument->id()
                    ) {
                        throw new RuntimeException(
                            'El pedido no coincide con la reserva.'
                        );
                    }

                    // No liberar boletos vinculados a pedidos pagados
                    // o con un estado diferente de pendiente.
                    if (($order['status'] ?? null) !== 'pending') {
                        throw new RuntimeException(
                            'El pedido asociado no esta pendiente.'
                        );
                    }
                }

                $timestamp = new Timestamp($now);

                // Actualizar el inventario una sola vez.
                $transaction->update($ticketDocument, [
                    [
                        'path' => 'reserved',
                        'value' => $reserved - $quantity,
                    ],
                    [
                        'path' => 'updatedAt',
                        'value' => $timestamp,
                    ],
                ]);

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

                // Vencer tambien el pedido dentro de la transaccion.
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
            preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id) === 1;
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
