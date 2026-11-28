<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReviewModerationController extends Controller
{
    /**
     * Ocultar una resena publicada.
     */
    public function hide(
        Request $request,
        string $eventId,
        string $reviewId,
        FirestoreClient $firestore,
        AuditLogger $auditLogger
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        // Verificar que exista una identidad autenticada.
        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'Debes iniciar sesion.',
            ], 401);
        }

        // La moderacion corresponde solo al administrador.
        if ($role !== 'admin') {
            return response()->json([
                'message' => 'No tienes permisos para moderar resenas.',
            ], 403);
        }

        // Validar los identificadores recibidos.
        if (
            !$this->validId($eventId) ||
            !$this->validId($reviewId)
        ) {
            return response()->json([
                'message' => 'La resena no existe.',
            ], 404);
        }

        // Esta accion no recibe campos adicionales.
        if (
            $request->all() !== [] ||
            $request->query() !== []
        ) {
            return response()->json([
                'message' => 'La solicitud contiene parametros no validos.',
            ], 422);
        }

        try {
            $eventReference = $firestore
                ->collection('events')
                ->document($eventId);

            $reviewReference = $eventReference
                ->collection('reviews')
                ->document($reviewId);

            $auditReference = $firestore
                ->collection('audit_logs')
                ->newDocument();

            $result = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $eventReference,
                    $reviewReference,
                    $auditReference,
                    $eventId,
                    $reviewId,
                    $uid,
                    $role,
                    $auditLogger
                ): array {
                    // Confirmar que el evento exista.
                    $eventSnapshot = $transaction->snapshot(
                        $eventReference
                    );

                    if (!$eventSnapshot->exists()) {
                        return [
                            'code' => 404,
                            'message' => 'El evento no existe.',
                        ];
                    }

                    // Consultar el estado actual de la resena.
                    $reviewSnapshot = $transaction->snapshot(
                        $reviewReference
                    );

                    if (!$reviewSnapshot->exists()) {
                        return [
                            'code' => 404,
                            'message' => 'La resena no existe.',
                        ];
                    }

                    $review = $reviewSnapshot->data();

                    if (
                        !is_array($review) ||
                        ($review['eventId'] ?? null) !== $eventId ||
                        ($review['userId'] ?? null) !== $reviewId
                    ) {
                        return [
                            'code' => 409,
                            'message' => 'Los datos de la resena son inconsistentes.',
                        ];
                    }

                    $currentStatus = $review['status'] ?? null;

                    // Evitar registrar dos veces la misma accion.
                    if ($currentStatus === 'hidden') {
                        return [
                            'code' => 409,
                            'message' => 'La resena ya esta oculta.',
                        ];
                    }

                    if ($currentStatus !== 'published') {
                        return [
                            'code' => 422,
                            'message' => 'No se permite ocultar esta resena.',
                        ];
                    }

                    // Registrar el actor y el campo modificado.
                    $auditData = $auditLogger->prepare(
                        'hidden',
                        'reviews',
                        $eventId . ':' . $reviewId,
                        $uid,
                        $role,
                        ['status']
                    );

                    if ($auditData === null) {
                        return [
                            'code' => 500,
                            'message' => 'No fue posible preparar la auditoria.',
                        ];
                    }

                    // Cambiar el estado y registrar la auditoria.
                    // Ambas operaciones forman una sola transaccion.
                    $transaction->update(
                        $reviewReference,
                        [
                            [
                                'path' => 'status',
                                'value' => 'hidden',
                            ],
                            [
                                'path' => 'updatedAt',
                                'value' => FieldValue::serverTimestamp(),
                            ],
                        ]
                    );

                    $transaction->create(
                        $auditReference,
                        $auditData
                    );

                    return [
                        'code' => 200,
                        'message' => 'Resena ocultada correctamente.',
                        'review' => [
                            'id' => $reviewId,
                            'eventId' => $eventId,
                            'status' => 'hidden',
                        ],
                    ];
                }
            );

            $code = $result['code'] ?? 500;

            unset($result['code']);

            return response()->json($result, $code);

        } catch (Throwable $exception) {
            Log::error('Error al moderar resena BluePass', [
                'eventId' => $eventId,
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible moderar la resena.',
            ], 500);
        }
    }

    /**
     * Validar identificadores de Firestore.
     */
    private function validId(mixed $value): bool
    {
        return is_string($value) &&
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $value
            ) === 1;
    }
}
