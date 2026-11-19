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

class NewsController extends Controller
{
    /**
     * Registrar una noticia como borrador.
     */
    public function store(
        Request $request,
        FirestoreClient $firestore
    ): JsonResponse {
        // Obtener la identidad del usuario.
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'No fue posible verificar la identidad.',
            ], 401);
        }

        if (!in_array($role, ['seller', 'admin'], true)) {
            return response()->json([
                'message' => 'No tienes permisos para crear noticias.',
            ], 403);
        }

        // Comprobar los campos permitidos.
        $allowedFields = [
            'title',
            'content',
            'summary',
        ];

        $unauthorizedFields = array_diff(
            array_keys($request->all()),
            $allowedFields
        );

        if (!empty($unauthorizedFields)) {
            return response()->json([
                'message' => 'Se enviaron campos no autorizados.',
            ], 422);
        }

        // Validar los datos de la noticia.
        $validator = Validator::make($request->all(), [
            'title' => [
                'required',
                'string',
                'min:5',
                'max:180',
            ],
            'content' => [
                'required',
                'string',
                'min:10',
                'max:10000',
            ],
            'summary' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la noticia no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Limpiar los campos de texto.
        foreach (['title', 'content', 'summary'] as $field) {
            if (
                array_key_exists($field, $data) &&
                is_string($data[$field])
            ) {
                $data[$field] = trim($data[$field]);
            }
        }

        // Impedir títulos o contenidos vacíos.
        foreach (['title', 'content'] as $field) {
            if ($data[$field] === '') {
                return response()->json([
                    'message' => 'Los datos de la noticia no son válidos.',
                    'errors' => [
                        $field => [
                            'Este campo no puede estar vacío.',
                        ],
                    ],
                ], 422);
            }
        }

        try {
            // Crear el documento de Firestore.
            $document = $firestore
                ->collection('news')
                ->newDocument();

            $news = [
                'authorId' => $uid,
                'title' => $data['title'],
                'content' => $data['content'],
                'summary' => $data['summary'] ?? null,
                'status' => 'draft',
                'createdAt' => FieldValue::serverTimestamp(),
                'updatedAt' => FieldValue::serverTimestamp(),
            ];

            $document->set($news);

            return response()->json([
                'message' => 'Noticia registrada como borrador.',
                'news' => [
                    'id' => $document->id(),
                    'authorId' => $uid,
                    'title' => $news['title'],
                    'content' => $news['content'],
                    'summary' => $news['summary'],
                    'status' => 'draft',
                ],
            ], 201);

        } catch (Throwable $e) {
            Log::error('Error al registrar noticia BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible registrar la noticia.',
            ], 500);
        }
    }

    /**
     * Consultar las noticias publicadas.
     */
    public function index(FirestoreClient $firestore): JsonResponse
    {
        try {
            // Consultar únicamente noticias publicadas.
            $documents = $firestore
                ->collection('news')
                ->where('status', '=', 'published')
                ->limit(20)
                ->documents();

            $news = [];

            foreach ($documents as $document) {
                if (!$document->exists()) {
                    continue;
                }

                $data = $document->data();

                if (!is_array($data)) {
                    continue;
                }

                if (($data['status'] ?? null) !== 'published') {
                    continue;
                }

                $news[] = [
                    'id' => $document->id(),
                    'title' => $data['title'] ?? null,
                    'summary' => $data['summary'] ?? null,
                    'content' => $data['content'] ?? null,
                    'status' => 'published',
                ];
            }

            return response()->json([
                'message' => 'Noticias consultadas correctamente.',
                'news' => $news,
            ], 200);

        } catch (Throwable $e) {
            Log::error('Error al consultar noticias BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar las noticias.',
            ], 500);
        }
    }

    /**
     * Consultar una noticia publicada.
     */
    public function show(
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        if (trim($id) === '') {
            return response()->json([
                'message' => 'La noticia no existe.',
            ], 404);
        }

        try {
            // Buscar la noticia en Firestore.
            $document = $firestore
                ->collection('news')
                ->document($id);

            $snapshot = $document->snapshot();

            if (!$snapshot->exists()) {
                return response()->json([
                    'message' => 'La noticia no existe.',
                ], 404);
            }

            $data = $snapshot->data();

            // Ocultar noticias que no estén publicadas.
            if (
                !is_array($data) ||
                ($data['status'] ?? null) !== 'published'
            ) {
                return response()->json([
                    'message' => 'La noticia no existe.',
                ], 404);
            }

            return response()->json([
                'message' => 'Noticia consultada correctamente.',
                'news' => [
                    'id' => $id,
                    'title' => $data['title'] ?? null,
                    'content' => $data['content'] ?? null,
                    'summary' => $data['summary'] ?? null,
                    'status' => 'published',
                ],
            ], 200);

        } catch (Throwable $e) {
            Log::error('Error al consultar detalle de noticia BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar la noticia.',
            ], 500);
        }
    }

    /**
     * Editar una noticia existente.
     */
    public function update(
        Request $request,
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        // Obtener la identidad del usuario.
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'No fue posible verificar la identidad.',
            ], 401);
        }

        if (!in_array($role, ['seller', 'admin'], true)) {
            return response()->json([
                'message' => 'No tienes permisos para editar noticias.',
            ], 403);
        }

        if (trim($id) === '') {
            return response()->json([
                'message' => 'La noticia no existe.',
            ], 404);
        }

        // Solo se pueden modificar estos campos.
        $allowedFields = [
            'title',
            'content',
            'summary',
        ];

        $unauthorizedFields = array_diff(
            array_keys($request->all()),
            $allowedFields
        );

        if (!empty($unauthorizedFields)) {
            return response()->json([
                'message' => 'Se enviaron campos no autorizados.',
            ], 422);
        }

        if (empty($request->all())) {
            return response()->json([
                'message' => 'Debes proporcionar datos para actualizar.',
            ], 422);
        }

        // Validar únicamente los campos enviados.
        $validator = Validator::make($request->all(), [
            'title' => [
                'sometimes',
                'required',
                'string',
                'min:5',
                'max:180',
            ],
            'content' => [
                'sometimes',
                'required',
                'string',
                'min:10',
                'max:10000',
            ],
            'summary' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la noticia no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Eliminar espacios innecesarios.
        foreach (['title', 'content', 'summary'] as $field) {
            if (
                array_key_exists($field, $data) &&
                is_string($data[$field])
            ) {
                $data[$field] = trim($data[$field]);
            }
        }

        // Rechazar títulos o contenidos vacíos.
        foreach (['title', 'content'] as $field) {
            if (
                array_key_exists($field, $data) &&
                $data[$field] === ''
            ) {
                return response()->json([
                    'message' => 'Los datos de la noticia no son válidos.',
                    'errors' => [
                        $field => [
                            'Este campo no puede estar vacío.',
                        ],
                    ],
                ], 422);
            }
        }

        try {
            // Buscar la noticia.
            $document = $firestore
                ->collection('news')
                ->document($id);

            $snapshot = $document->snapshot();

            if (!$snapshot->exists()) {
                return response()->json([
                    'message' => 'La noticia no existe.',
                ], 404);
            }

            $news = $snapshot->data();

            if (!is_array($news)) {
                return response()->json([
                    'message' => 'No fue posible obtener la noticia.',
                ], 500);
            }

            // Verificar que el vendedor sea el autor.
            if (
                $role === 'seller' &&
                ($news['authorId'] ?? null) !== $uid
            ) {
                return response()->json([
                    'message' => 'No tienes permisos para editar esta noticia.',
                ], 403);
            }

            // Preparar los campos modificados.
            $updates = [];

            foreach ($data as $field => $value) {
                $updates[] = [
                    'path' => $field,
                    'value' => $value,
                ];
            }

            // Registrar la última modificación.
            $updates[] = [
                'path' => 'updatedAt',
                'value' => FieldValue::serverTimestamp(),
            ];

            $document->update($updates);

            return response()->json([
                'message' => 'Noticia actualizada correctamente.',
                'news' => array_merge(
                    [
                        'id' => $id,
                        'authorId' => $news['authorId'] ?? null,
                        'status' => $news['status'] ?? null,
                    ],
                    $data
                ),
            ], 200);

        } catch (Throwable $e) {
            Log::error('Error al actualizar noticia BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible actualizar la noticia.',
            ], 500);
        }
    }
}
