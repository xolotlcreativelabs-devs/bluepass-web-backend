<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class EventStatusController extends Controller
{
    /**
     * Publicar o despublicar un evento.
     */
    public function update(
        Request $request,
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        // Verificar la identidad del usuario.
        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'No fue posible verificar la identidad.',
            ], 401);
        }

        // Permitir únicamente vendedores y administradores.
        if (!in_array($role, ['seller', 'admin'], true)) {
            return response()->json([
                'message' => 'No tienes permisos para gestionar eventos.',
            ], 403);
        }

        // Validar el identificador del evento.
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) {
            return response()->json([
                'message' => 'El identificador del evento no es válido.',
            ], 422);
        }

        // Impedir que se modifiquen otros campos.
        if (array_diff(array_keys($request->all()), ['status'])) {
            return response()->json([
                'message' => 'Se enviaron campos no autorizados.',
            ], 422);
        }

        // Validar el estado solicitado.
        $validator = Validator::make($request->all(), [
            'status' => [
                'required',
                'string',
                'in:published,unpublished',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'El estado solicitado no es válido.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $newStatus = $validator->validated()['status'];

        try {
            $document = $firestore
                ->collection('events')
                ->document($id);

            // Comprobar el estado más reciente en Firestore.
            $result = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $document,
                    $uid,
                    $role,
                    $newStatus
                ): array {
                    $snapshot = $transaction->snapshot($document);

                    if (!$snapshot->exists()) {
                        return [
                            'code' => 404,
                            'message' => 'El evento no existe.',
                        ];
                    }

                    $event = $snapshot->data();

                    if (!is_array($event)) {
                        throw new RuntimeException(
                            'No fue posible leer los datos del evento.'
                        );
                    }

                    // Un vendedor solo puede gestionar sus eventos.
                    if (
                        $role === 'seller' &&
                        ($event['sellerId'] ?? null) !== $uid
                    ) {
                        throw new AuthorizationException(
                            'No tienes permisos sobre este evento.'
                        );
                    }

                    $currentStatus = $event['status'] ?? null;

                    if ($currentStatus === $newStatus) {
                        return [
                            'code' => 409,
                            'message' => 'El evento ya tiene ese estado.',
                        ];
                    }

                    // Publicar desde borrador o despublicado.
                    $canPublish = $newStatus === 'published'
                        && in_array(
                            $currentStatus,
                            ['draft', 'unpublished'],
                            true
                        );

                    // Despublicar solo eventos publicados.
                    $canUnpublish = $newStatus === 'unpublished'
                        && $currentStatus === 'published';

                    if (!$canPublish && !$canUnpublish) {
                        return [
                            'code' => 422,
                            'message' => 'No se permite este cambio de estado.',
                        ];
                    }

                    // Comprobar fechas al publicar o republicar.
                    if (
                        $newStatus === 'published' &&
                        !$this->hasValidDates($event)
                    ) {
                        return [
                            'code' => 422,
                            'message' =>
                                'El evento necesita fechas de inicio y '.
                                'finalización válidas para publicarse.',
                        ];
                    }

                    // Guardar únicamente el estado y la fecha del cambio.
                    $transaction->update($document, [
                        [
                            'path' => 'status',
                            'value' => $newStatus,
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => FieldValue::serverTimestamp(),
                        ],
                    ]);

                    return [
                        'code' => 200,
                        'message' => $newStatus === 'published'
                            ? 'Evento publicado correctamente.'
                            : 'Evento despublicado correctamente.',
                        'event' => [
                            'id' => $document->id(),
                            'status' => $newStatus,
                        ],
                    ];
                }
            );

            return response()->json(
                array_filter(
                    $result,
                    fn ($key) => $key !== 'code',
                    ARRAY_FILTER_USE_KEY
                ),
                $result['code']
            );
        } catch (AuthorizationException $e) {
            return response()->json([
                'message' => 'No tienes permisos sobre este evento.',
            ], 403);
        } catch (Throwable $e) {
            Log::error('Error al cambiar el estado del evento', [
                'eventId' => $id,
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible cambiar el estado del evento.',
            ], 500);
        }
    }

    /**
     * Comprobar que el evento tenga fechas válidas.
     */
    private function hasValidDates(array $event): bool
    {
        $start = $event['date'] ?? null;
        $end = $event['endAt'] ?? null;

        // Convertir las fechas almacenadas en Firestore.
        if ($start instanceof Timestamp) {
            $start = $start->get();
        }

        if ($end instanceof Timestamp) {
            $end = $end->get();
        }

        // No publicar eventos sin fechas reconocidas.
        if (
            !$start instanceof DateTimeInterface ||
            !$end instanceof DateTimeInterface
        ) {
            return false;
        }

        // La finalización debe ser posterior al inicio.
        return $end->getTimestamp() > $start->getTimestamp();
    }
}
