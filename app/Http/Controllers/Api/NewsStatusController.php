<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class NewsStatusController extends Controller
{
    /**
     * Publicar o despublicar una noticia.
     */
    public function update(
        Request $request,
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'No fue posible verificar la identidad.',
            ], 401);
        }

        if (!in_array($role, ['seller', 'admin'], true)) {
            return response()->json([
                'message' => 'No tienes permisos para gestionar noticias.',
            ], 403);
        }

        if (trim($id) === '') {
            return response()->json([
                'message' => 'La noticia no existe.',
            ], 404);
        }

        // Impedir modificar campos adicionales.
        $unauthorizedFields = array_diff(
            array_keys($request->all()),
            ['status']
        );

        if (!empty($unauthorizedFields)) {
            return response()->json([
                'message' => 'Se enviaron campos no autorizados.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'status' => [
                'required',
                'string',
                'in:published,unpublished',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'El estado de la noticia no es válido.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $requestedStatus = $validator->validated()['status'];

        try {
            // Usar una transacción para evitar cambios simultáneos.
            $result = $firestore->runTransaction(
                function ($transaction) use (
                    $firestore,
                    $id,
                    $uid,
                    $role,
                    $requestedStatus
                ) {
                    $document = $firestore
                        ->collection('news')
                        ->document($id);

                    $snapshot = $transaction->snapshot($document);

                    if (!$snapshot->exists()) {
                        return [
                            'code' => 404,
                            'message' => 'La noticia no existe.',
                        ];
                    }

                    $news = $snapshot->data();

                    if (!is_array($news)) {
                        return [
                            'code' => 500,
                            'message' => 'No fue posible obtener la noticia.',
                        ];
                    }

                    // El vendedor solo administra noticias propias.
                    if (
                        $role === 'seller' &&
                        ($news['authorId'] ?? null) !== $uid
                    ) {
                        return [
                            'code' => 403,
                            'message' => 'No tienes permisos sobre esta noticia.',
                        ];
                    }

                    $currentStatus = $news['status'] ?? null;

                    if ($currentStatus === $requestedStatus) {
                        return [
                            'code' => 409,
                            'message' => 'La noticia ya tiene ese estado.',
                        ];
                    }

                    $allowedTransitions = [
                        'draft' => ['published'],
                        'published' => ['unpublished'],
                        'unpublished' => ['published'],
                    ];

                    if (
                        !isset($allowedTransitions[$currentStatus]) ||
                        !in_array(
                            $requestedStatus,
                            $allowedTransitions[$currentStatus],
                            true
                        )
                    ) {
                        return [
                            'code' => 422,
                            'message' => 'No se permite este cambio de estado.',
                        ];
                    }

                    // Exigir contenido mínimo antes de publicar.
                    if (
                        $requestedStatus === 'published' &&
                        !$this->hasValidContent($news)
                    ) {
                        return [
                            'code' => 422,
                            'message' => 'La noticia debe tener título y contenido válidos para publicarse.',
                        ];
                    }

                    $transaction->update($document, [
                        [
                            'path' => 'status',
                            'value' => $requestedStatus,
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => FieldValue::serverTimestamp(),
                        ],
                    ]);

                    return [
                        'code' => 200,
                        'message' => $requestedStatus === 'published'
                            ? 'Noticia publicada correctamente.'
                            : 'Noticia despublicada correctamente.',
                        'news' => [
                            'id' => $document->id(),
                            'status' => $requestedStatus,
                        ],
                    ];
                }
            );

            $code = $result['code'] ?? 500;

            return response()->json(
                array_filter(
                    [
                        'message' => $result['message']
                            ?? 'No fue posible actualizar el estado.',
                        'news' => $result['news'] ?? null,
                    ],
                    fn ($value) => $value !== null
                ),
                $code
            );

        } catch (Throwable $e) {
            Log::error('Error al cambiar estado de noticia BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible actualizar el estado de la noticia.',
            ], 500);
        }
    }

    /**
     * Comprobar que la noticia tenga contenido publicable.
     */
    private function hasValidContent(array $news): bool
    {
        $title = $news['title'] ?? null;
        $content = $news['content'] ?? null;

        return is_string($title)
            && mb_strlen(trim($title)) >= 5
            && mb_strlen(trim($title)) <= 180
            && is_string($content)
            && mb_strlen(trim($content)) >= 10
            && mb_strlen(trim($content)) <= 10000;
    }
}
