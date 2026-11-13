<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Throwable;

class AuthController extends Controller
{
    /**
     * Validar el inicio de sesión de un usuario registrado.
     */
    public function session(
        Request $request,
        Auth $auth,
        FirestoreClient $firestore
    ): JsonResponse {
        $data = $request->validate([
            'idToken' => ['required', 'string'],
        ]);

        try {
            // 1. Verificar que el token sea válido y no esté revocado.
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

            // 2. Obtener el UID del usuario autenticado.
            $uid = $verifiedToken->claims()->get('sub');

            if (!is_string($uid) || $uid === '') {
                return response()->json([
                    'message' => 'No se pudo identificar al usuario.',
                ], 401);
            }

            // 3. Consultar el usuario en Firebase Authentication.
            $firebaseUser = $auth->getUser($uid);

            if ($firebaseUser->disabled) {
                return response()->json([
                    'message' => 'La cuenta está deshabilitada.',
                ], 403);
            }

            // 4. Exigir la verificación del correo electrónico.
            if (!$firebaseUser->emailVerified) {
                return response()->json([
                    'message' => 'Debes verificar tu correo electrónico.',
                ], 403);
            }

            $email = $firebaseUser->email;

            if (!is_string($email) || $email === '') {
                return response()->json([
                    'message' => 'La cuenta no tiene un correo válido.',
                ], 422);
            }

            // 5. Consultar el perfil existente en Firestore.
            $document = $firestore
                ->collection('users')
                ->document($uid);

            $snapshot = $document->snapshot();

            // El inicio de sesión no debe crear usuarios.
            if (!$snapshot->exists()) {
                return response()->json([
                    'message' => 'No existe un perfil registrado en BluePass.',
                ], 404);
            }

            $profile = $snapshot->data();

            // 6. Verificar que el perfil corresponda al UID.
            if (($profile['authSubject'] ?? null) !== $uid) {
                return response()->json([
                    'message' => 'El perfil no coincide con la identidad.',
                ], 403);
            }

            // 7. Bloquear cuentas suspendidas o inactivas.
            if (($profile['status'] ?? null) !== 'active') {
                return response()->json([
                    'message' => 'La cuenta no está activa.',
                ], 403);
            }

            // 8. Devolver únicamente los datos autorizados.
            return response()->json([
                'message' => 'Identidad verificada correctamente.',
                'user' => [
                    'id' => $uid,
                    'name' => $profile['name'],
                    'email' => $profile['email'],
                    'role' => $profile['role'],
                    'status' => $profile['status'],
                ],
            ], 200);

        } catch (Throwable $e) {
            Log::error('Error de autenticación BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible procesar la autenticación.',
            ], 500);
        }
    }

    /**
     * Revocar los tokens de actualización del usuario autenticado.
     *
     * Esta operación afecta a todas sus sesiones.
     */
    public function logout(
        Request $request,
        Auth $auth
    ): JsonResponse {
        $data = $request->validate([
            'idToken' => ['required', 'string'],
        ]);

        try {
            // 1. Verificar que el token sea válido y no esté revocado.
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

            // 2. Obtener el UID directamente del token verificado.
            $uid = $verifiedToken->claims()->get('sub');

            if (!is_string($uid) || $uid === '') {
                return response()->json([
                    'message' => 'No se pudo identificar al usuario.',
                ], 401);
            }

            // 3. Revocar los tokens de actualización de Firebase.
            $auth->revokeRefreshTokens($uid);

            // 4. Confirmar la operación.
            return response()->json([
                'message' => 'Se han revocado las sesiones del usuario.',
            ], 200);

        } catch (Throwable $e) {
            Log::error('Error al cerrar sesiones de BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible cerrar las sesiones.',
            ], 500);
        }
    }
}
