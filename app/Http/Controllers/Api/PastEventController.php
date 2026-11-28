<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PastEventController extends Controller
{
    private const SCAN_LIMIT = 500;

    /**
     * Consultar los eventos publicados que ya finalizaron.
     */
    public function index(
        Request $request,
        FirestoreClient $firestore
    ): JsonResponse {
        $parameters = $request->query();

        if (
            array_diff(
                array_keys($parameters),
                ['page', 'perPage', 'search', 'category']
            )
        ) {
            return $this->invalidParameters();
        }

        $validator = Validator::make($parameters, [
            'page' => 'sometimes|required|integer|min:1|max:10000',
            'perPage' => 'sometimes|required|integer|min:1|max:50',
            'search' => 'sometimes|required|string|min:2|max:100',
            'category' => 'sometimes|required|string|min:2|max:80',
        ]);

        if ($validator->fails()) {
            return $this->invalidParameters();
        }

        $data = $validator->validated();

        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['perPage'] ?? 20);

        $search = isset($data['search'])
            ? mb_strtolower(trim($data['search']), 'UTF-8')
            : null;

        $category = isset($data['category'])
            ? mb_strtolower(trim($data['category']), 'UTF-8')
            : null;

        if (
            ($search !== null && $search === '') ||
            ($category !== null && $category === '')
        ) {
            return $this->invalidParameters();
        }

        try {
            $documents = $firestore
                ->collection('events')
                ->where('status', '=', 'published')
                ->limit(self::SCAN_LIMIT)
                ->documents();

            $events = [];
            $scanned = 0;
            $now = new DateTimeImmutable();

            foreach ($documents as $document) {
                $scanned++;

                if (!$document->exists()) {
                    continue;
                }

                $event = $document->data();

                if (!$this->isPublic($event)) {
                    continue;
                }

                if (!$this->isPast($event, $now)) {
                    continue;
                }

                if ($category !== null) {
                    $eventCategory = $event['category'] ?? null;

                    if (
                        !is_string($eventCategory) ||
                        mb_strtolower(
                            trim($eventCategory),
                            'UTF-8'
                        ) !== $category
                    ) {
                        continue;
                    }
                }

                if ($search !== null) {
                    $fields = [];

                    foreach (
                        ['name', 'description', 'venue', 'category']
                        as $field
                    ) {
                        if (is_string($event[$field] ?? null)) {
                            $fields[] = mb_strtolower(
                                $event[$field],
                                'UTF-8'
                            );
                        }
                    }

                    if (!str_contains(implode(' ', $fields), $search)) {
                        continue;
                    }
                }

                $events[] = $this->formatEvent(
                    $document->id(),
                    $event
                );
            }

            usort(
                $events,
                static function (array $a, array $b): int {
                    $dateComparison = strcmp(
                        $b['endAt'],
                        $a['endAt']
                    );

                    return $dateComparison !== 0
                        ? $dateComparison
                        : strcmp($a['id'], $b['id']);
                }
            );

            $matched = count($events);
            $offset = ($page - 1) * $perPage;

            return response()->json([
                'message' => 'Eventos pasados consultados correctamente.',
                'events' => array_slice(
                    $events,
                    $offset,
                    $perPage
                ),
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'matchedInWindow' => $matched,
                    'hasNextPage' => $offset + $perPage < $matched,
                    'scanLimit' => self::SCAN_LIMIT,
                    'scanLimitReached' => $scanned >= self::SCAN_LIMIT,
                ],
            ], 200);
        } catch (Throwable $exception) {
            Log::error('Error al consultar eventos pasados', [
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar los eventos pasados.',
            ], 500);
        }
    }

    /**
     * Consultar el detalle de un evento finalizado.
     */
    public function show(
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        if (!$this->validId($id)) {
            return $this->eventNotFound();
        }

        try {
            $snapshot = $firestore
                ->collection('events')
                ->document($id)
                ->snapshot();

            if (!$snapshot->exists()) {
                return $this->eventNotFound();
            }

            $event = $snapshot->data();

            if (
                !$this->isPublic($event) ||
                !$this->isPast($event, new DateTimeImmutable())
            ) {
                return $this->eventNotFound();
            }

            return response()->json([
                'message' => 'Evento pasado consultado correctamente.',
                'event' => $this->formatEvent($id, $event),
            ], 200);
        } catch (Throwable $exception) {
            Log::error('Error al consultar evento pasado', [
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar el evento pasado.',
            ], 500);
        }
    }

    /**
     * Consultar las reseñas publicadas de un evento.
     */
    public function reviews(
        Request $request,
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        if (!$this->validId($id)) {
            return $this->eventNotFound();
        }

        $parameters = $request->query();

        if (
            array_diff(
                array_keys($parameters),
                ['page', 'perPage']
            )
        ) {
            return $this->invalidParameters();
        }

        $validator = Validator::make($parameters, [
            'page' => 'sometimes|required|integer|min:1|max:10000',
            'perPage' => 'sometimes|required|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return $this->invalidParameters();
        }

        $data = $validator->validated();
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['perPage'] ?? 20);

        try {
            $eventDocument = $firestore
                ->collection('events')
                ->document($id);

            $eventSnapshot = $eventDocument->snapshot();

            if (!$eventSnapshot->exists()) {
                return $this->eventNotFound();
            }

            $event = $eventSnapshot->data();

            if (
                !$this->isPublic($event) ||
                !$this->isPast($event, new DateTimeImmutable())
            ) {
                return $this->eventNotFound();
            }

            $documents = $eventDocument
                ->collection('reviews')
                ->limit(self::SCAN_LIMIT)
                ->documents();

            $reviews = [];
            $scanned = 0;

            foreach ($documents as $document) {
                $scanned++;

                if (!$document->exists()) {
                    continue;
                }

                $review = $document->data();

                if (
                    !is_array($review) ||
                    ($review['status'] ?? null) !== 'published'
                ) {
                    continue;
                }

                $rating = $review['rating'] ?? null;
                $comment = $review['comment'] ?? null;

                if (
                    !is_int($rating) ||
                    $rating < 1 ||
                    $rating > 5 ||
                    !is_string($comment) ||
                    trim($comment) === ''
                ) {
                    continue;
                }

                $reviews[] = [
                    'id' => $document->id(),
                    'eventId' => $id,
                    'rating' => $rating,
                    'comment' => $comment,
                    'authorName' => is_string(
                        $review['authorName'] ?? null
                    )
                        ? $review['authorName']
                        : 'Comprador',
                    'createdAt' => $this->formatDate(
                        $review['createdAt'] ?? null
                    ),
                    'updatedAt' => $this->formatDate(
                        $review['updatedAt'] ?? null
                    ),
                ];
            }

            usort(
                $reviews,
                static function (array $a, array $b): int {
                    $dateComparison = strcmp(
                        $b['createdAt'] ?? '',
                        $a['createdAt'] ?? ''
                    );

                    return $dateComparison !== 0
                        ? $dateComparison
                        : strcmp($a['id'], $b['id']);
                }
            );

            $matched = count($reviews);
            $offset = ($page - 1) * $perPage;

            return response()->json([
                'message' => 'Reseñas consultadas correctamente.',
                'eventId' => $id,
                'reviews' => array_slice(
                    $reviews,
                    $offset,
                    $perPage
                ),
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'matchedInWindow' => $matched,
                    'hasNextPage' => $offset + $perPage < $matched,
                    'scanLimit' => self::SCAN_LIMIT,
                    'scanLimitReached' => $scanned >= self::SCAN_LIMIT,
                ],
            ], 200);
        } catch (Throwable $exception) {
            Log::error('Error al consultar reseñas BluePass', [
                'eventId' => $id,
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar las reseñas.',
            ], 500);
        }
    }

    /**
     * Comprobar que el evento es publico.
     */
    private function isPublic(mixed $event): bool
    {
        return is_array($event) &&
            ($event['status'] ?? null) === 'published' &&
            ($event['visibility'] ?? 'public') === 'public' &&
            ($event['isPrivate'] ?? false) === false;
    }

    /**
     * Comprobar si el evento ya finalizo.
     */
    private function isPast(
        mixed $event,
        DateTimeImmutable $now
    ): bool {
        if (!is_array($event)) {
            return false;
        }

        $start = $this->readDate($event['date'] ?? null);
        $end = $this->readDate($event['endAt'] ?? null);

        return $start !== null &&
            $end !== null &&
            $end > $start &&
            $now >= $end;
    }

    /**
     * Preparar los datos publicos del evento.
     */
    private function formatEvent(
        string $id,
        array $event
    ): array {
        return [
            'id' => $id,
            'name' => $event['name'] ?? null,
            'description' => $event['description'] ?? null,
            'category' => $event['category'] ?? null,
            'venue' => $event['venue'] ?? null,
            'timezone' => $event['timezone'] ?? null,
            'date' => $this->formatDate(
                $event['date'] ?? null
            ),
            'endAt' => $this->formatDate(
                $event['endAt'] ?? null
            ),
            'imageUrl' => $event['imageUrl'] ?? null,
            'status' => 'published',
            'isPast' => true,
        ];
    }

    /**
     * Convertir las fechas de Firestore.
     */
    private function readDate(
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

    private function formatDate(mixed $value): ?string
    {
        return $this->readDate($value)?->format(
            DateTimeInterface::ATOM
        );
    }

    private function validId(string $id): bool
    {
        return preg_match(
            '/^[A-Za-z0-9_-]{1,128}$/D',
            $id
        ) === 1;
    }

    private function eventNotFound(): JsonResponse
    {
        return response()->json([
            'message' => 'El evento no existe.',
        ], 404);
    }

    private function invalidParameters(): JsonResponse
    {
        return response()->json([
            'message' => 'Los parametros de consulta no son validos.',
        ], 422);
    }
}
