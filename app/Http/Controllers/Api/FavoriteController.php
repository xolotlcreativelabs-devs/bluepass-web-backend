<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class FavoriteController extends Controller
{
    /**
     * Consultar los favoritos del usuario autenticado.
     */
    public function index(
        Request $request,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $this->getUid($request);

        if ($uid === null) {
            return $this->identityError();
        }

        try {
            $documents = $firestore
                ->collection('users')
                ->document($uid)
                ->collection('favorites')
                ->limit(100)
                ->documents();

            $favorites = [];

            foreach ($documents as $document) {
                if (!$document->exists()) {
                    continue;
                }

                $data = $document->data();

                if (!is_array($data)) {
                    continue;
                }

                $favorites[] = [
                    'eventId' => $document->id(),
                    'createdAt' => $this->formatDate(
                        $data['createdAt'] ?? null
                    ),
                ];
            }

            return response()->json([
                'message' => 'Favoritos consultados correctamente.',
                'favorites' => $favorites,
            ], 200);

        } catch (Throwable $e) {
            Log::error('Error al consultar favoritos BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar los favoritos.',
            ], 500);
        }
    }

    /**
     * Agregar un evento a favoritos sin duplicados.
     */
    public function store(
        Request $request,
        string $eventId,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $this->getUid($request);

        if ($uid === null) {
            return $this->identityError();
        }

        if (!$this->validEventId($eventId)) {
            return $this->eventNotFound();
        }

        try {
            $eventDocument = $firestore
                ->collection('events')
                ->document($eventId);

            $favoriteDocument = $firestore
                ->collection('users')
                ->document($uid)
                ->collection('favorites')
                ->document($eventId);

            // La transaccion evita crear favoritos duplicados.
            $result = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $eventDocument,
                    $favoriteDocument,
                    $eventId
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
                        !is_array($event) ||
                        ($event['status'] ?? null) !== 'published'
                    ) {
                        return [
                            'code' => 404,
                            'message' => 'El evento no existe.',
                        ];
                    }

                    $favoriteSnapshot = $transaction->snapshot(
                        $favoriteDocument
                    );

                    // Guardar nuevamente un favorito no crea otro.
                    if ($favoriteSnapshot->exists()) {
                        return [
                            'code' => 200,
                            'message' => 'El evento ya esta en favoritos.',
                            'eventId' => $eventId,
                            'created' => false,
                        ];
                    }

                    $transaction->create(
                        $favoriteDocument,
                        [
                            'eventId' => $eventId,
                            'createdAt' => FieldValue::serverTimestamp(),
                        ]
                    );

                    return [
                        'code' => 201,
                        'message' => 'Evento agregado a favoritos.',
                        'eventId' => $eventId,
                        'created' => true,
                    ];
                }
            );

            $statusCode = $result['code'];

            unset($result['code']);

            return response()->json($result, $statusCode);

        } catch (Throwable $e) {
            Log::error('Error al guardar favorito BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible agregar el favorito.',
            ], 500);
        }
    }

    /**
     * Eliminar un favorito del usuario autenticado.
     */
    public function destroy(
        Request $request,
        string $eventId,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $this->getUid($request);

        if ($uid === null) {
            return $this->identityError();
        }

        if (!$this->validEventId($eventId)) {
            return $this->eventNotFound();
        }

        try {
            $favoriteDocument = $firestore
                ->collection('users')
                ->document($uid)
                ->collection('favorites')
                ->document($eventId);

            $result = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $favoriteDocument,
                    $eventId
                ): array {
                    $snapshot = $transaction->snapshot(
                        $favoriteDocument
                    );

                    if (!$snapshot->exists()) {
                        return [
                            'message' => 'El evento no estaba en favoritos.',
                            'eventId' => $eventId,
                            'removed' => false,
                        ];
                    }

                    $transaction->delete($favoriteDocument);

                    return [
                        'message' => 'Favorito eliminado correctamente.',
                        'eventId' => $eventId,
                        'removed' => true,
                    ];
                }
            );

            return response()->json($result, 200);

        } catch (Throwable $e) {
            Log::error('Error al eliminar favorito BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible eliminar el favorito.',
            ], 500);
        }
    }

    /**
     * Obtener la identidad validada por Firebase.
     */
    private function getUid(Request $request): ?string
    {
        $uid = $request->attributes->get('firebase_uid');
        $profile = $request->attributes->get('firebase_profile');

        if (
            !is_string($uid) ||
            $uid === '' ||
            !is_array($profile)
        ) {
            return null;
        }

        return $uid;
    }

    /**
     * Comprobar el identificador del evento.
     */
    private function validEventId(string $eventId): bool
    {
        return preg_match(
            '/^[A-Za-z0-9_-]{1,128}$/D',
            $eventId
        ) === 1;
    }

    /**
     * Respuesta para una identidad no valida.
     */
    private function identityError(): JsonResponse
    {
        return response()->json([
            'message' => 'No fue posible verificar la identidad.',
        ], 401);
    }

    /**
     * Respuesta para eventos inexistentes.
     */
    private function eventNotFound(): JsonResponse
    {
        return response()->json([
            'message' => 'El evento no existe.',
        ], 404);
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
