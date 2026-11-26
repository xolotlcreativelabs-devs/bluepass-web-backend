<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Exception\AbortedException;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class MultiTicketReservationService
{
    private const RESERVATION_MINUTES = 10;
    private const MAX_TRANSACTION_ATTEMPTS = 5;

    public function __construct(
        private FirestoreClient $firestore,
        private MultiTicketSelectionValidator $selectionValidator
    ) {
    }

    /**
     * Crear una reserva con varios tipos de boletos.
     */
    public function create(
        string $eventId,
        string $userId,
        array $input
    ): array {
        if (
            !$this->validId($eventId) ||
            !$this->validId($userId)
        ) {
            throw new InvalidArgumentException(
                'El evento o comprador no es valido.'
            );
        }

        $selection = $this->selectionValidator->validate($input);

        $items = $selection['items'];
        $totalQuantity = $selection['quantity'];

        $userDocument = $this->firestore
            ->collection('users')
            ->document($userId);

        $eventDocument = $this->firestore
            ->collection('events')
            ->document($eventId);

        $reservationDocument = $eventDocument
            ->collection('reservations')
            ->newDocument();

        $ticketDocuments = [];

        foreach ($items as $item) {
            $ticketTypeId = $item['ticketTypeId'];

            $ticketDocuments[$ticketTypeId] = $eventDocument
                ->collection('ticketTypes')
                ->document($ticketTypeId);
        }

        for (
            $attempt = 1;
            $attempt <= self::MAX_TRANSACTION_ATTEMPTS;
            $attempt++
        ) {
            try {
                return $this->firestore->runTransaction(
                    function (Transaction $transaction) use (
                        $userDocument,
                        $eventDocument,
                        $reservationDocument,
                        $ticketDocuments,
                        $eventId,
                        $userId,
                        $items,
                        $totalQuantity
                    ): array {
                        // Leer todos los datos antes de escribir.
                        $userSnapshot = $transaction->snapshot(
                            $userDocument
                        );

                        $eventSnapshot = $transaction->snapshot(
                            $eventDocument
                        );

                        $ticketSnapshots = [];

                        foreach ($ticketDocuments as $typeId => $document) {
                            $ticketSnapshots[$typeId] =
                                $transaction->snapshot($document);
                        }

                        // Una cuenta en eliminacion no puede reservar.
                        if (!$userSnapshot->exists()) {
                            return $this->failure(
                                403,
                                'La cuenta del comprador no esta disponible.'
                            );
                        }

                        $user = $userSnapshot->data();

                        if (
                            !is_array($user) ||
                            ($user['authSubject'] ?? null) !== $userId ||
                            ($user['role'] ?? null) !== 'customer' ||
                            ($user['status'] ?? null) !== 'active'
                        ) {
                            return $this->failure(
                                403,
                                'La cuenta del comprador no esta activa.'
                            );
                        }

                        if (!$eventSnapshot->exists()) {
                            return $this->failure(
                                404,
                                'El evento no existe.'
                            );
                        }

                        $event = $eventSnapshot->data();

                        if (
                            !is_array($event) ||
                            ($event['status'] ?? null) !== 'published'
                        ) {
                            return $this->failure(
                                404,
                                'El evento no esta publicado.'
                            );
                        }

                        $now = new DateTimeImmutable();

                        $eventDate = $this->readTimestamp(
                            $event['date'] ?? null
                        );

                        if ($eventDate === null || $now >= $eventDate) {
                            return $this->failure(
                                409,
                                'El evento ya comenzo o no tiene fecha valida.'
                            );
                        }

                        $reservationItems = [];
                        $inventoryUpdates = [];

                        foreach ($items as $item) {
                            $typeId = $item['ticketTypeId'];
                            $quantity = $item['quantity'];

                            $snapshot = $ticketSnapshots[$typeId];

                            if (!$snapshot->exists()) {
                                return $this->failure(
                                    404,
                                    'Uno de los tipos de boleto no existe.'
                                );
                            }

                            $ticket = $snapshot->data();

                            if (
                                !is_array($ticket) ||
                                ($ticket['status'] ?? null) !== 'active'
                            ) {
                                return $this->failure(
                                    409,
                                    'Uno de los tipos de boleto no esta activo.'
                                );
                            }

                            $saleStart = $this->readTimestamp(
                                $ticket['saleStartAt'] ?? null
                            );

                            $saleEnd = $this->readTimestamp(
                                $ticket['saleEndAt'] ?? null
                            );

                            if (
                                $saleStart === null ||
                                $saleEnd === null ||
                                $saleStart >= $saleEnd ||
                                $now < $saleStart ||
                                $now >= $saleEnd
                            ) {
                                return $this->failure(
                                    409,
                                    'Uno de los tipos de boleto esta fuera de venta.'
                                );
                            }

                            $capacity = $ticket['capacity'] ?? null;
                            $sold = $ticket['sold'] ?? null;
                            $reserved = $ticket['reserved'] ?? 0;
                            $price = $ticket['price'] ?? null;

                            if (
                                !is_int($capacity) ||
                                !is_int($sold) ||
                                !is_int($reserved) ||
                                !is_int($price) ||
                                $capacity < 1 ||
                                $sold < 0 ||
                                $reserved < 0 ||
                                $price < 0 ||
                                $sold > $capacity ||
                                $reserved > $capacity - $sold
                            ) {
                                throw new RuntimeException(
                                    'El inventario de boletos es inconsistente.'
                                );
                            }

                            $available = $capacity - $sold - $reserved;

                            if ($quantity > $available) {
                                return [
                                    'code' => 409,
                                    'message' =>
                                        'No hay suficientes boletos disponibles.',
                                    'ticketTypeId' => $typeId,
                                    'available' => $available,
                                ];
                            }

                            $reservationItems[] = [
                                'ticketTypeId' => $typeId,
                                'quantity' => $quantity,
                                'unitPrice' => $price,
                                'currency' => 'MXN',
                            ];

                            $inventoryUpdates[$typeId] = [
                                'reserved' => $reserved + $quantity,
                            ];
                        }

                        $expiresAt = $now->modify(
                            '+' . self::RESERVATION_MINUTES . ' minutes'
                        );

                        $timestamp = new Timestamp($now);

                        $reservation = [
                            'eventId' => $eventId,
                            'userId' => $userId,
                            'items' => $reservationItems,
                            'quantity' => $totalQuantity,
                            'currency' => 'MXN',
                            'status' => 'active',
                            'createdAt' => $timestamp,
                            'updatedAt' => $timestamp,
                            'expiresAt' => new Timestamp($expiresAt),
                        ];

                        // La reserva y el inventario se guardan juntos.
                        $transaction->create(
                            $reservationDocument,
                            $reservation
                        );

                        foreach ($inventoryUpdates as $typeId => $update) {
                            $transaction->update(
                                $ticketDocuments[$typeId],
                                [
                                    [
                                        'path' => 'reserved',
                                        'value' => $update['reserved'],
                                    ],
                                    [
                                        'path' => 'updatedAt',
                                        'value' => $timestamp,
                                    ],
                                ]
                            );
                        }

                        return [
                            'code' => 201,
                            'message' =>
                                'Reserva multiple creada correctamente.',
                            'reservation' => [
                                'id' => $reservationDocument->id(),
                                'eventId' => $eventId,
                                'items' => $reservationItems,
                                'quantity' => $totalQuantity,
                                'currency' => 'MXN',
                                'status' => 'active',
                                'expiresAt' => $expiresAt->format(
                                    DateTimeInterface::ATOM
                                ),
                            ],
                        ];
                    }
                );
            } catch (AbortedException $exception) {
                if ($attempt === self::MAX_TRANSACTION_ATTEMPTS) {
                    Log::warning(
                        'Conflicto de concurrencia al crear reserva multiple',
                        [
                            'attempts' => $attempt,
                            'exception' => get_class($exception),
                        ]
                    );

                    return $this->failure(
                        503,
                        'La reserva esta siendo procesada. Intenta nuevamente.'
                    );
                }

                // Esperar un intervalo diferente en cada reintento.
                usleep(
                    (100000 * $attempt) +
                    random_int(0, 200000)
                );
            }
        }

        return $this->failure(
            503,
            'No fue posible completar la reserva.'
        );
    }

    private function failure(
        int $code,
        string $message
    ): array {
        return [
            'code' => $code,
            'message' => $message,
        ];
    }

    private function validId(mixed $id): bool
    {
        return is_string($id) &&
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $id
            ) === 1;
    }

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
