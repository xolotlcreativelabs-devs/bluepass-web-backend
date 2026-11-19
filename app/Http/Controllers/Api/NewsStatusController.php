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
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class NewsStatusController extends Controller
{
    /**
     * Publicar o despublicar una noticia.
     */
    public function update(
        Request $request,
        string $id,
        FirestoreClient $firestore,
        AuditLogger $auditLogger
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

        // Validar el nuevo estado solicitado.
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
            // Obtener la referencia de la noticia.
            $document = $firestore
                ->collection('news')
                ->document($id);

            // Consultar y modificar los datos en una transacción.
            $result = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $firestore,
                    $document,
                    $uid,
                    $role,
                    $requestedStatus,
                    $auditLogger
                ): array {
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

                    // El vendedor solo administra sus propias noticias.
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

                    // Evitar cambios innecesarios.
                    if ($currentStatus === $requestedStatus) {
                        return [
                            'code' => 409,
                            'message' => 'La noticia ya tiene ese estado.',
                        ];
                    }

                    // Definir los cambios de estado permitidos.
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

                    // Verificar el contenido antes de publicar.
                    if (
                        $requestedStatus === 'published' &&
                        !$this->hasValidContent($news)
                    ) {
                        return [
                            'code' => 422,
                            'message' =>
                                'La noticia debe tener título y contenido ' .
                                'válidos para publicarse.',
                        ];
                    }

                    // Preparar la auditoría con el servicio centralizado.
                    $auditData = $auditLogger->prepare(
                        $requestedStatus,
                        'news',
                        $document->id(),
                        $uid,
                        $role,
                        ['status']
                    );

                    if ($auditData === null) {
                        throw new RuntimeException(
                            'No fue posible preparar la auditoría.'
                        );
                    }

                    $auditDocument = $firestore
                        ->collection('audit_logs')
                        ->newDocument();

                    // Actualizar el estado de la noticia.
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

                    // Registrar la acción dentro de la misma transacción.
                    $transaction->create(
                        $auditDocument,
                        $auditData
                    );

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
            // Guardar información técnica sin exponer credenciales.
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
