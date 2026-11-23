<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class EventCatalogController extends Controller
{
    /**
     * Consultar eventos publicados.
     */
    public function index(
        Request $request,
        FirestoreClient $firestore
    ): JsonResponse {
        $parameters = $request->query();

        // Permitir solo los parametros del catalogo.
        $allowed = ['search', 'category', 'page', 'perPage'];

        if (array_diff(array_keys($parameters), $allowed)) {
            return response()->json([
                'message' => 'Los parametros de consulta no son validos.',
            ], 422);
        }

        $validator = Validator::make($parameters, [
            'search' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:100',
            ],
            'category' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:80',
            ],
            'page' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:10000',
            ],
            'perPage' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:50',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los parametros de consulta no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $hasFilters = !empty($parameters);
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['perPage'] ?? 20);

        $search = isset($data['search'])
            ? mb_strtolower(trim($data['search']), 'UTF-8')
            : null;

        $category = isset($data['category'])
            ? mb_strtolower(trim($data['category']), 'UTF-8')
            : null;

        // Rechazar textos compuestos solo por espacios.
        if (
            ($search !== null && $search === '') ||
            ($category !== null && $category === '')
        ) {
            return response()->json([
                'message' => 'Los parametros de consulta no son validos.',
            ], 422);
        }

        try {
            // Consultar solo eventos publicados.
            $query = $firestore
                ->collection('events')
                ->where('status', '=', 'published');

            // Conservar el comportamiento original sin filtros.
            $scanLimit = $hasFilters ? 500 : 20;

            $documents = $query
                ->limit($scanLimit)
                ->documents();

            $events = [];
            $scanned = 0;

            foreach ($documents as $document) {
                $scanned++;

                if (!$document->exists()) {
                    continue;
                }

                $event = $document->data();

                if (
                    !is_array($event) ||
                    ($event['status'] ?? null) !== 'published'
                ) {
                    continue;
                }

                // Filtrar por categoria.
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

                // Buscar coincidencias en campos publicos.
                if ($search !== null) {
                    $searchable = [];

                    foreach (
                        ['name', 'description', 'venue', 'category']
                        as $field
                    ) {
                        $value = $event[$field] ?? null;

                        if (is_string($value)) {
                            $searchable[] = mb_strtolower(
                                $value,
                                'UTF-8'
                            );
                        }
                    }

                    $text = implode(' ', $searchable);

                    if (!str_contains($text, $search)) {
                        continue;
                    }
                }

                $events[] = $this->formatEvent(
                    $document->id(),
                    $event
                );
            }

            // Mantener un orden estable durante la paginacion.
            if ($hasFilters) {
                usort(
                    $events,
                    static fn (array $a, array $b): int =>
                        strcmp($a['id'], $b['id'])
                );
            }

            // Mantener la respuesta original sin parametros.
            if (!$hasFilters) {
                return response()->json([
                    'message' => 'Eventos consultados correctamente.',
                    'events' => $events,
                ], 200);
            }

            $matched = count($events);
            $offset = ($page - 1) * $perPage;

            $pageEvents = array_slice(
                $events,
                $offset,
                $perPage
            );

            return response()->json([
                'message' => 'Eventos consultados correctamente.',
                'events' => $pageEvents,
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'matchedInWindow' => $matched,
                    'hasNextPage' => $offset + $perPage < $matched,
                    'scanLimit' => $scanLimit,
                    'scanLimitReached' => $scanned >= $scanLimit,
                ],
            ], 200);

        } catch (Throwable $e) {
            Log::error('Error al consultar eventos BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar los eventos.',
            ], 500);
        }
    }

    /**
     * Consultar el detalle de un evento publicado.
     */
    public function show(
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) {
            return response()->json([
                'message' => 'El evento no existe.',
            ], 404);
        }

        try {
            $document = $firestore
                ->collection('events')
                ->document($id);

            $snapshot = $document->snapshot();

            if (!$snapshot->exists()) {
                return response()->json([
                    'message' => 'El evento no existe.',
                ], 404);
            }

            $data = $snapshot->data();

            if (
                !is_array($data) ||
                ($data['status'] ?? null) !== 'published'
            ) {
                return response()->json([
                    'message' => 'El evento no existe.',
                ], 404);
            }

            return response()->json([
                'message' => 'Evento consultado correctamente.',
                'event' => $this->formatEvent($id, $data),
            ], 200);

        } catch (Throwable $e) {
            Log::error(
                'Error al consultar detalle de evento BluePass',
                [
                    'exception' => get_class($e),
                ]
            );

            return response()->json([
                'message' => 'No fue posible consultar el evento.',
            ], 500);
        }
    }

    /**
     * Preparar los datos publicos de un evento.
     */
    private function formatEvent(
        string $id,
        array $data
    ): array {
        return [
            'id' => $id,
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'venue' => $data['venue'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'date' => $this->formatDate(
                $data['date'] ?? null
            ),
            'endAt' => $this->formatDate(
                $data['endAt'] ?? null
            ),
            'imageUrl' => $data['imageUrl'] ?? null,
            'status' => 'published',
        ];
    }

    /**
     * Convertir fechas de Firestore a ISO 8601.
     */
    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof Timestamp) {
            $value = $value->get();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return null;
    }
}
