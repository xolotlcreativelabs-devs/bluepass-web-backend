<?php

namespace App\Http\Middleware;

use Closure;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FirebaseAuth
{
    public function __construct(
        private Auth $auth,
        private FirestoreClient $firestore
    ) {
    }

    /**
     * Verificar la identidad y los permisos del usuario.
     */
    public function handle(
        Request $request,
        Closure $next,
        string ...$roles
    ): Response {
        $idToken = $request->bearerToken();

        if (!is_string($idToken) || $idToken === '') {
            return response()->json([
                'message' => 'Debes proporcionar un token de autenticación.',
            ], 401);
        }

        try {
            // Verificar el token y su revocación.
            try {
                $verifiedToken = $this->auth->verifyIdToken(
                    $idToken,
                    true
                );
            } catch (FailedToVerifyToken | RevokedIdToken $e) {
                return response()->json([
                    'message' => 'Token de autenticación inválido, expirado o revocado.',
                ], 401);
            }

            $uid = $verifiedToken->claims()->get('sub');

            if (!is_string($uid) || $uid === '') {
                return response()->json([
                    'message' => 'No se pudo identificar al usuario.',
                ], 401);
            }

            // Comprobar el estado de la cuenta en Firebase.
            $firebaseUser = $this->auth->getUser($uid);

            if ($firebaseUser->disabled) {
                return response()->json([
                    'message' => 'La cuenta está deshabilitada.',
                ], 403);
            }

            if (!$firebaseUser->emailVerified) {
                return response()->json([
                    'message' => 'Debes verificar tu correo electrónico.',
                ], 403);
            }

            // Consultar el perfil del usuario autenticado.
            $snapshot = $this->firestore
                ->collection('users')
                ->document($uid)
                ->snapshot();

            if (!$snapshot->exists()) {
                return response()->json([
                    'message' => 'No existe un perfil registrado en BluePass.',
                ], 404);
            }

            $profile = $snapshot->data();

            if (!is_array($profile)) {
                return response()->json([
                    'message' => 'No fue posible obtener el perfil.',
                ], 500);
            }

            // Verificar que el perfil corresponda al usuario.
            if (($profile['authSubject'] ?? null) !== $uid) {
                return response()->json([
                    'message' => 'El perfil no coincide con la identidad.',
                ], 403);
            }

            if (($profile['status'] ?? null) !== 'active') {
                return response()->json([
                    'message' => 'La cuenta no está activa.',
                ], 403);
            }

            // Validar el rol registrado en Firestore.
            $role = $profile['role'] ?? null;

            $validRoles = [
                'customer',
                'seller',
                'staff',
                'admin',
            ];

            if (
                !is_string($role) ||
                !in_array($role, $validRoles, true)
            ) {
                return response()->json([
                    'message' => 'El usuario no tiene un rol válido.',
                ], 403);
            }

            // Comprobar los roles permitidos para la ruta.
            if (!empty($roles) && !in_array($role, $roles, true)) {
                return response()->json([
                    'message' => 'No tienes permisos para realizar esta operación.',
                ], 403);
            }

            // Compartir los datos verificados con el controlador.
            $request->attributes->set('firebase_uid', $uid);
            $request->attributes->set('firebase_role', $role);
            $request->attributes->set('firebase_profile', $profile);

        } catch (Throwable $e) {
            Log::error('Error al verificar permisos BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible verificar los permisos.',
            ], 500);
        }

        // Continuar sin capturar errores de otros controladores.
        return $next($request);
    }
}
