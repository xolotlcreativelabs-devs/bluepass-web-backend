<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SellerReviewController extends Controller
{
    /**
     * Consultar las resenas de un evento del vendedor.
     */
    public function index(
        Request $request,
        string $eventId,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'Debes iniciar sesion.',
            ], 401);
        }

        if ($role !== 'seller') {
            return response()->json([
                'message' => 'No tienes permisos para consultar estas resenas.',
            ], 403);
        }

        if (
            preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $eventId) !== 1
        ) {
            return response()->json([
                'message' => 'El evento no existe.',
            ], 404);
        }

        // Permitir solamente los parametros de paginacion.
        $params = $request->query();

        if (array_diff(array_keys($params), ['page', 'limit'])) {
            return $this->invalidParams();
        }

        $page = $params['page'] ?? '1';
        $limit = $params['limit'] ?? '20';

        if (
            !is_string($page) ||
            !is_string($limit) ||
            preg_match('/^[1-9][0-9]*$/D', $page) !== 1 ||
            preg_match('/^[1-9][0-9]*$/D', $limit) !== 1 ||
            strlen($page) > 4 ||
            strlen($limit) > 2
        ) {
            return $this->invalidParams();
        }

        $page = (int) $page;
        $limit = (int) $limit;

        if (
            $page < 1 ||
            $page > 1000 ||
            $limit < 1 ||
            $limit > 50
        ) {
            return $this->invalidParams();
        }

        try {
            $eventReference = $firestore
                ->collection('events')
                ->document($eventId);

            $eventSnapshot = $eventReference->snapshot();

            if (!$eventSnapshot->exists()) {
                return response()->json([
                    'message' => 'El evento no existe.',
                ], 404);
            }

            $event = $eventSnapshot->data();

            // El vendedor solo puede consultar sus propios eventos.
            if (
                !is_array($event) ||
                ($event['sellerId'] ?? null) !== $uid
            ) {
                return response()->json([
                    'message' => 'No tienes permisos para consultar este evento.',
                ], 403);
            }

            $offset = ($page - 1) * $limit;

            // Recorrer en orden antes de aplicar la paginacion.
            // Los registros inconsistentes no deben ocupar
            // posiciones dentro de una pagina.
            $documents = $eventReference
                ->collection('reviews')
                ->orderBy('createdAt', 'DESC')
                ->documents();

            $reviews = [];
            $validCount = 0;
            $hasMore = false;

            foreach ($documents as $document) {
                if (!$document->exists()) {
                    continue;
                }

                $review = $document->data();

                if (!is_array($review)) {
                    continue;
                }

                // Evitar mostrar resenas con asociaciones incorrectas.
                if (
                    ($review['eventId'] ?? null) !== $eventId ||
                    ($review['sellerId'] ?? null) !== $uid ||
                    ($review['userId'] ?? null) !== $document->id()
                ) {
                    continue;
                }

                $validCount++;

                // Saltar las resenas validas de paginas anteriores.
                if ($validCount <= $offset) {
                    continue;
                }

                // Una resena valida adicional confirma otra pagina.
                if (count($reviews) >= $limit) {
                    $hasMore = true;
                    break;
                }

                $reviews[] = [
                    'id' => $document->id(),
                    'eventId' => $eventId,
                    'sellerId' => $uid,
                    'authorName' => $review['authorName']
                        ?? 'Comprador',
                    'rating' => $review['rating'] ?? null,
                    'comment' => $review['comment'] ?? null,
                    'status' => $review['status'] ?? null,
                    'createdAt' => $this->formatDate(
                        $review['createdAt'] ?? null
                    ),
                    'updatedAt' => $this->formatDate(
                        $review['updatedAt'] ?? null
                    ),
                ];
            }

            return response()->json([
                'eventId' => $eventId,
                'reviews' => $reviews,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'hasMore' => $hasMore,
                    'nextPage' => $hasMore ? $page + 1 : null,
                ],
            ], 200);

        } catch (Throwable $exception) {
            Log::error('Error al consultar resenas del vendedor', [
                'eventId' => $eventId,
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar las resenas.',
            ], 500);
        }
    }

    /**
     * Convertir fechas de Firestore a texto ISO 8601.
     */
    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof Timestamp) {
            $value = $value->get();
        }

        return $value instanceof DateTimeInterface
            ? $value->format(DateTimeInterface::ATOM)
            : null;
    }

    /**
     * Responder cuando la paginacion no es valida.
     */
    private function invalidParams(): JsonResponse
    {
        return response()->json([
            'message' => 'Los parametros de consulta no son validos.',
        ], 422);
    }
}
