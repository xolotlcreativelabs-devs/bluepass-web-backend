<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReviewController extends Controller
{
    private const SCAN_LIMIT = 500;

    /**
     * Publicar una resena del comprador.
     */
    public function store(
        Request $request,
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        return $this->saveReview(
            $request,
            $id,
            $firestore,
            false
        );
    }

    /**
     * Editar la resena propia.
     */
    public function update(
        Request $request,
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        return $this->saveReview(
            $request,
            $id,
            $firestore,
            true
        );
    }

    /**
     * Validar y guardar una resena.
     */
    private function saveReview(
        Request $request,
        string $eventId,
        FirestoreClient $firestore,
        bool $editing
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'Debes iniciar sesion.',
            ], 401);
        }

        if ($role !== 'customer') {
            return response()->json([
                'message' => 'No tienes permisos para publicar resenas.',
            ], 403);
        }

        if (!$this->validId($uid)) {
            return response()->json([
                'message' => 'La identidad del comprador no es valida.',
            ], 403);
        }

        if (!$this->validId($eventId)) {
            return $this->eventNotFound();
        }

        $input = $request->all();

        if (
            array_diff(
                array_keys($input),
                ['rating', 'comment']
            ) ||
            !array_key_exists('rating', $input) ||
            !array_key_exists('comment', $input)
        ) {
            return $this->invalidReview();
        }

        $rating = $input['rating'];
        $comment = $input['comment'];

        if (
            !is_int($rating) ||
            $rating < 1 ||
            $rating > 5 ||
            !is_string($comment)
        ) {
            return $this->invalidReview();
        }

        $comment = trim($comment);
        $commentLength = mb_strlen($comment, 'UTF-8');

        if (
            $commentLength < 10 ||
            $commentLength > 1000
        ) {
            return $this->invalidReview();
        }

        if ($request->query() !== []) {
            return $this->invalidReview();
        }

        $profile = $request->attributes->get(
            'firebase_profile'
        );

        $authorName = 'Comprador';

        if (
            is_array($profile) &&
            is_string($profile['name'] ?? null)
        ) {
            $name = trim($profile['name']);

            if ($name !== '' && mb_strlen($name) <= 150) {
                $authorName = $name;
            }
        }

        try {
            $eventDocument = $firestore
                ->collection('events')
                ->document($eventId);

            $reviewDocument = $eventDocument
                ->collection('reviews')
                ->document($uid);

            // Localizar boletos del comprador sin confiar
            // en referencias enviadas por el frontend.
            $ticketDocuments = $firestore
                ->collection('tickets')
                ->where('userId', '=', $uid)
                ->limit(self::SCAN_LIMIT)
                ->documents();

            $candidates = [];

            foreach ($ticketDocuments as $document) {
                if (!$document->exists()) {
                    continue;
                }

                $ticket = $document->data();

                if (
                    !is_array($ticket) ||
                    ($ticket['userId'] ?? null) !== $uid ||
                    ($ticket['eventId'] ?? null) !== $eventId ||
                    !in_array(
                        $ticket['status'] ?? null,
                        ['valid', 'used'],
                        true
                    )
                ) {
                    continue;
                }

                $orderId = $ticket['orderId'] ?? null;

                if (!$this->validId($orderId)) {
                    continue;
                }

                $candidates[] = [
                    'ticketId' => $document->id(),
                    'orderId' => $orderId,
                ];
            }

            $result = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $firestore,
                    $eventDocument,
                    $reviewDocument,
                    $eventId,
                    $uid,
                    $rating,
                    $comment,
                    $authorName,
                    $editing,
                    $candidates
                ): array {
                    $eventSnapshot = $transaction->snapshot(
                        $eventDocument
                    );

                    if (!$eventSnapshot->exists()) {
                        return [
                            'code' => 404,
                            'message' => 'El evento no existe.',
                        ];
                    }

                    $event = $eventSnapshot->data();

                    if (
                        !$this->isPublic($event)
                    ) {
                        return [
                            'code' => 404,
                            'message' => 'El evento no existe.',
                        ];
                    }

                    if (!$this->isPast($event)) {
                        return [
                            'code' => 409,
                            'message' => 'El evento aun no ha finalizado.',
                        ];
                    }

                    $sellerId = $event['sellerId'] ?? null;

                    if (!$this->validId($sellerId)) {
                        return [
                            'code' => 409,
                            'message' => 'El evento no tiene un vendedor valido.',
                        ];
                    }

                    $reviewSnapshot = $transaction->snapshot(
                        $reviewDocument
                    );

                    $existing = $reviewSnapshot->exists()
                        ? $reviewSnapshot->data()
                        : null;

                    if ($editing && !is_array($existing)) {
                        return [
                            'code' => 404,
                            'message' => 'No tienes una resena para editar.',
                        ];
                    }

                    if (!$editing && $reviewSnapshot->exists()) {
                        return [
                            'code' => 409,
                            'message' => 'Ya existe una resena para este evento.',
                        ];
                    }

                    if (
                        $editing &&
                        ($existing['userId'] ?? null) !== $uid
                    ) {
                        return [
                            'code' => 403,
                            'message' => 'No puedes editar esta resena.',
                        ];
                    }

                    if (
                        $editing &&
                        ($existing['eventId'] ?? null) !== $eventId
                    ) {
                        return [
                            'code' => 409,
                            'message' => 'Los datos de la resena son inconsistentes.',
                        ];
                    }

                    $eligible = false;

                    foreach ($candidates as $candidate) {
                        $ticketId = $candidate['ticketId'];
                        $orderId = $candidate['orderId'];

                        $ticketReference = $firestore
                            ->collection('tickets')
                            ->document($ticketId);

                        $ticketSnapshot = $transaction->snapshot(
                            $ticketReference
                        );

                        if (!$ticketSnapshot->exists()) {
                            continue;
                        }

                        $ticket = $ticketSnapshot->data();

                        if (
                            !is_array($ticket) ||
                            ($ticket['userId'] ?? null) !== $uid ||
                            ($ticket['eventId'] ?? null) !== $eventId ||
                            ($ticket['orderId'] ?? null) !== $orderId ||
                            !in_array(
                                $ticket['status'] ?? null,
                                ['valid', 'used'],
                                true
                            )
                        ) {
                            continue;
                        }

                        $orderReference = $firestore
                            ->collection('orders')
                            ->document($orderId);

                        $orderSnapshot = $transaction->snapshot(
                            $orderReference
                        );

                        if (!$orderSnapshot->exists()) {
                            continue;
                        }

                        $order = $orderSnapshot->data();

                        if (
                            !is_array($order) ||
                            ($order['userId'] ?? null) !== $uid ||
                            ($order['eventId'] ?? null) !== $eventId ||
                            ($order['sellerId'] ?? null) !== $sellerId ||
                            ($order['status'] ?? null) !== 'paid'
                        ) {
                            continue;
                        }

                        $issuedIds = $order['issuedTicketIds']
                            ?? null;

                        if (
                            !is_array($issuedIds) ||
                            !in_array(
                                $ticketId,
                                $issuedIds,
                                true
                            )
                        ) {
                            continue;
                        }

                        $paymentReference = $order[
                            'paymentReference'
                        ] ?? null;

                        if (
                            !is_string($paymentReference) ||
                            preg_match(
                                '/^[A-Za-z0-9._-]{8,128}$/D',
                                $paymentReference
                            ) !== 1
                        ) {
                            continue;
                        }

                        $paymentDocument = $firestore
                            ->collection('paymentReferences')
                            ->document(
                                hash(
                                    'sha256',
                                    $paymentReference
                                )
                            );

                        $paymentSnapshot = $transaction->snapshot(
                            $paymentDocument
                        );

                        if (!$paymentSnapshot->exists()) {
                            continue;
                        }

                        $payment = $paymentSnapshot->data();

                        if (
                            !is_array($payment) ||
                            ($payment['orderId'] ?? null) !== $orderId ||
                            ($payment['paymentReference'] ?? null)
                                !== $paymentReference
                        ) {
                            continue;
                        }

                        $eligible = true;
                        break;
                    }

                    if (!$eligible) {
                        return [
                            'code' => 403,
                            'message' => 'Necesitas una compra pagada con un boleto valido para resenar este evento.',
                        ];
                    }

                    $now = new Timestamp(
                        new DateTimeImmutable()
                    );

                    if ($editing) {
                        // Mantener el estado de moderacion.
                        $status = $existing['status']
                            ?? 'hidden';

                        $transaction->update(
                            $reviewDocument,
                            [
                                [
                                    'path' => 'rating',
                                    'value' => $rating,
                                ],
                                [
                                    'path' => 'comment',
                                    'value' => $comment,
                                ],
                                [
                                    'path' => 'updatedAt',
                                    'value' => $now,
                                ],
                            ]
                        );

                        return [
                            'code' => 200,
                            'message' => 'Resena actualizada correctamente.',
                            'review' => [
                                'id' => $uid,
                                'eventId' => $eventId,
                                'rating' => $rating,
                                'comment' => $comment,
                                'status' => $status,
                            ],
                        ];
                    }

                    $transaction->create(
                        $reviewDocument,
                        [
                            'eventId' => $eventId,
                            'userId' => $uid,
                            'sellerId' => $sellerId,
                            'authorName' => $authorName,
                            'rating' => $rating,
                            'comment' => $comment,
                            'status' => 'published',
                            'createdAt' => $now,
                            'updatedAt' => $now,
                        ]
                    );

                    return [
                        'code' => 201,
                        'message' => 'Resena publicada correctamente.',
                        'review' => [
                            'id' => $uid,
                            'eventId' => $eventId,
                            'rating' => $rating,
                            'comment' => $comment,
                            'status' => 'published',
                        ],
                    ];
                }
            );

            $code = $result['code'];
            unset($result['code']);

            return response()->json($result, $code);
        } catch (Throwable $exception) {
            Log::error('Error al guardar resena BluePass', [
                'eventId' => $eventId,
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible guardar la resena.',
            ], 500);
        }
    }

    /**
     * Confirmar que el evento sea publico.
     */
    private function isPublic(mixed $event): bool
    {
        return is_array($event) &&
            ($event['status'] ?? null) === 'published' &&
            ($event['visibility'] ?? 'public') === 'public' &&
            ($event['isPrivate'] ?? false) === false;
    }

    /**
     * Verificar la fecha de finalizacion.
     */
    private function isPast(mixed $event): bool
    {
        if (!is_array($event)) {
            return false;
        }

        $start = $this->readTimestamp(
            $event['date'] ?? null
        );

        $end = $this->readTimestamp(
            $event['endAt'] ?? null
        );

        $now = new DateTimeImmutable();

        return $start !== null &&
            $end !== null &&
            $end > $start &&
            $now >= $end;
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

        return DateTimeImmutable::createFromInterface(
            $value
        );
    }

    private function validId(mixed $value): bool
    {
        return is_string($value) &&
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $value
            ) === 1;
    }

    private function eventNotFound(): JsonResponse
    {
        return response()->json([
            'message' => 'El evento no existe.',
        ], 404);
    }

    private function invalidReview(): JsonResponse
    {
        return response()->json([
            'message' => 'La calificacion o el comentario no son validos.',
        ], 422);
    }
}
