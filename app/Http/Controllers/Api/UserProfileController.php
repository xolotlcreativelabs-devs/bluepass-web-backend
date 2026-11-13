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

class UserProfileController extends Controller
{
    /**
     * Consultar el perfil del usuario autenticado.
     */
    public function show(Request $request): JsonResponse
    {
        // Obtener la identidad verificada por el middleware.
        $uid = $request->attributes->get('firebase_uid');
        $profile = $request->attributes->get('firebase_profile');

        if (!is_string($uid) || !is_array($profile)) {
            return response()->json([
                'message' => 'No fue posible verificar la identidad.',
            ], 401);
        }

        return response()->json([
            'message' => 'Perfil consultado correctamente.',
            'user' => [
                'id' => $uid,
                'name' => $profile['name'] ?? '',
                'email' => $profile['email'] ?? '',
                'role' => $profile['role'],
                'status' => $profile['status'],
            ],
        ], 200);
    }

    /**
     * Actualizar el nombre del usuario autenticado.
     */
    public function update(
        Request $request,
        FirestoreClient $firestore
    ): JsonResponse {
        // Obtener la identidad verificada por el middleware.
        $uid = $request->attributes->get('firebase_uid');
        $profile = $request->attributes->get('firebase_profile');

        if (!is_string($uid) || !is_array($profile)) {
            return response()->json([
                'message' => 'No fue posible verificar la identidad.',
            ], 401);
        }

        // Rechazar campos que el usuario no puede modificar.
        $unauthorizedFields = array_diff(
            array_keys($request->all()),
            ['name']
        );

        if (!empty($unauthorizedFields)) {
            return response()->json([
                'message' => 'Se enviaron campos no autorizados.',
            ], 422);
        }

        $name = $request->input('name');

        if (is_string($name)) {
            $name = trim($name);
        }

        // Validar el nombre.
        $validator = Validator::make(
            ['name' => $name],
            [
                'name' => [
                    'required',
                    'string',
                    'min:2',
                    'max:100',
                ],
            ],
            [
                'name.required' => 'El nombre es obligatorio.',
                'name.string' => 'El nombre debe ser texto.',
                'name.min' => 'El nombre debe tener al menos 2 caracteres.',
                'name.max' => 'El nombre no puede superar 100 caracteres.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos proporcionados no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Actualizar únicamente el nombre y la fecha.
            $firestore
                ->collection('users')
                ->document($uid)
                ->update([
                    [
                        'path' => 'name',
                        'value' => $name,
                    ],
                    [
                        'path' => 'updatedAt',
                        'value' => FieldValue::serverTimestamp(),
                    ],
                ]);

            return response()->json([
                'message' => 'Perfil actualizado correctamente.',
                'user' => [
                    'id' => $uid,
                    'name' => $name,
                    'email' => $profile['email'] ?? '',
                    'role' => $profile['role'],
                    'status' => $profile['status'],
                ],
            ], 200);

        } catch (Throwable $e) {
            Log::error('Error al actualizar perfil BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible actualizar el perfil.',
            ], 500);
        }
    }
}
