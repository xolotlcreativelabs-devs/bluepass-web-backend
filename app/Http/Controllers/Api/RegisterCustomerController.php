<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterCustomerRequest;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Throwable;

class RegisterCustomerController extends Controller
{
    /**
     * Registrar el perfil de un comprador en BluePass.
     */
    public function store(
        RegisterCustomerRequest $request,
        Auth $auth,
        FirestoreClient $firestore
    ): JsonResponse {
        $data = $request->validated();

        try {
            // 1. Verificar la identidad con Firebase Authentication.
            try {
                $verifiedToken = $auth->verifyIdToken(
                    $data['idToken'],
                    true
                );
            } catch (FailedToVerifyToken | RevokedIdToken $e) {
                return response()->json([
                    'message' => 'Token de autenticación inválido, expirado o revocado.',
                ], 401);
            }

            // 2. Obtener el UID del token verificado.
            $uid = $verifiedToken->claims()->get('sub');

            if (!is_string($uid) || $uid === '') {
                return response()->json([
                    'message' => 'No se pudo identificar al usuario.',
                ], 401);
            }

            // 3. Consultar los datos confiables de Firebase.
            $firebaseUser = $auth->getUser($uid);

            if ($firebaseUser->disabled) {
                return response()->json([
                    'message' => 'La cuenta está deshabilitada.',
                ], 403);
            }

            $email = $firebaseUser->email;

            if (!is_string($email) || $email === '') {
                return response()->json([
                    'message' => 'La cuenta no tiene un correo válido.',
                ], 422);
            }

            // 4. Buscar el perfil en Firestore.
            $document = $firestore
                ->collection('users')
                ->document($uid);

            if ($document->snapshot()->exists()) {
                return response()->json([
                    'message' => 'El usuario ya está registrado en BluePass.',
                ], 409);
            }

            // 5. Crear el perfil con permisos seguros.
            // No aceptar roles ni estados enviados por el cliente.
            $profile = [
                'name' => trim($data['name']),
                'email' => $email,
                'role' => 'customer',
                'status' => 'active',
                'authSubject' => $uid,
                'createdAt' => FieldValue::serverTimestamp(),
                'updatedAt' => FieldValue::serverTimestamp(),
            ];

            // create() evita sobrescribir un documento existente.
            $document->create($profile);

            // 6. Confirmar el registro.
            return response()->json([
                'message' => 'Cliente registrado correctamente.',
                'emailVerificationRequired' => !$firebaseUser->emailVerified,
                'user' => [
                    'id' => $uid,
                    'name' => $profile['name'],
                    'email' => $profile['email'],
                    'role' => $profile['role'],
                    'status' => $profile['status'],
                ],
            ], 201);

        } catch (Throwable $e) {
            Log::error('Error al registrar cliente BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible completar el registro.',
            ], 500);
        }
    }
}
