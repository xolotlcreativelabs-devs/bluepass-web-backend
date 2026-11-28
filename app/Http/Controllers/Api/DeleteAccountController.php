<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth;
use Throwable;

class DeleteAccountController extends Controller
{
    public function destroy(
        Request $request,
        Auth $auth,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $profile = $request->attributes->get('firebase_profile');

        if (
            !is_string($uid) ||
            $uid === '' ||
            !is_array($profile)
        ) {
            return response()->json([
                'message' => 'No fue posible verificar la identidad.',
            ], 401);
        }

        if (($profile['role'] ?? null) !== 'customer') {
            return response()->json([
                'message' => 'Solo los compradores pueden utilizar esta opcion.',
            ], 403);
        }

        if ($request->all() !== []) {
            return response()->json([
                'message' => 'Esta solicitud no admite datos adicionales.',
            ], 422);
        }

        $idToken = $request->bearerToken();

        if (!is_string($idToken) || $idToken === '') {
            return response()->json([
                'message' => 'Debes iniciar sesion nuevamente.',
            ], 401);
        }

        try {
            $token = $auth->verifyIdToken($idToken, true);

            if ($token->claims()->get('sub') !== $uid) {
                return response()->json([
                    'message' => 'La identidad no coincide.',
                ], 403);
            }

            $authTime = $token->claims()->get('auth_time');

            if (!is_numeric($authTime)) {
                return response()->json([
                    'message' => 'Debes iniciar sesion nuevamente.',
                ], 401);
            }

            $elapsed = time() - (int) $authTime;

            if ($elapsed < -60 || $elapsed > 300) {
                return response()->json([
                    'message' => 'Vuelve a iniciar sesion para confirmar la eliminacion.',
                ], 403);
            }
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'No fue posible validar la sesion.',
            ], 401);
        }

        $locked = false;
        $authDeletionStarted = false;
        $authDeleted = false;
        $userDocument = null;

        try {
            $userDocument = $firestore
                ->collection('users')
                ->document($uid);

            // Bloquear la cuenta mientras se prepara la eliminacion.
            $result = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $userDocument,
                    $uid
                ): array {
                    $snapshot = $transaction->snapshot($userDocument);

                    if (!$snapshot->exists()) {
                        return [
                            'code' => 404,
                            'message' => 'No se encontro la cuenta.',
                        ];
                    }

                    $data = $snapshot->data();

                    if (
                        !is_array($data) ||
                        ($data['authSubject'] ?? null) !== $uid ||
                        ($data['role'] ?? null) !== 'customer' ||
                        ($data['status'] ?? null) !== 'active'
                    ) {
                        return [
                            'code' => 403,
                            'message' => 'La cuenta no esta disponible para eliminacion.',
                        ];
                    }

                    $transaction->update($userDocument, [
                        [
                            'path' => 'status',
                            'value' => 'deletion_pending',
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => FieldValue::serverTimestamp(),
                        ],
                    ]);

                    return ['code' => 200];
                }
            );

            if (($result['code'] ?? 500) !== 200) {
                return response()->json([
                    'message' => $result['message']
                        ?? 'No fue posible preparar la eliminacion.',
                ], $result['code'] ?? 500);
            }

            $locked = true;

            // Comprobar si existen registros asociados a la cuenta.
            if ($this->hasRelatedRecords(
                $firestore,
                $userDocument,
                $uid
            )) {
                if (!$this->restoreUser(
                    $firestore,
                    $userDocument,
                    $uid
                )) {
                    return response()->json([
                        'message' => 'La cuenta requiere una revision antes de continuar.',
                    ], 500);
                }

                $locked = false;

                return response()->json([
                    'message' => 'La cuenta tiene informacion asociada y requiere un proceso de eliminacion especial.',
                    'code' => 'ACCOUNT_HAS_RELATED_RECORDS',
                ], 409);
            }

            // A partir de aqui, Firebase puede haber eliminado
            // la identidad incluso si devuelve un error.
            $authDeletionStarted = true;

            $auth->deleteUser($uid);
            $authDeleted = true;

            // Eliminar el perfil solo despues de confirmar
            // que Firebase Authentication elimino la identidad.
            $userDocument->delete();

            $locked = false;

            return response()->json([
                'message' => 'Cuenta eliminada correctamente.',
            ], 200);
        } catch (Throwable $exception) {
            Log::error('Error al eliminar cuenta BluePass', [
                'uid' => $uid,
                'exception' => get_class($exception),
                'authDeletionStarted' => $authDeletionStarted,
                'authDeleted' => $authDeleted,
            ]);

            // Si todavia no se intento eliminar la identidad,
            // restaurar el estado anterior.
            if (
                $locked &&
                !$authDeletionStarted &&
                $userDocument !== null
            ) {
                $restored = $this->restoreUser(
                    $firestore,
                    $userDocument,
                    $uid
                );

                if (!$restored) {
                    Log::warning(
                        'No fue posible restaurar la cuenta bloqueada',
                        ['uid' => $uid]
                    );
                }
            }

            // Si la eliminacion de Authentication fallo,
            // comprobar si la identidad sigue existiendo.
            if (
                $authDeletionStarted &&
                !$authDeleted &&
                $locked &&
                $userDocument !== null
            ) {
                $restored = $this->restoreAfterAuthFailure(
                    $auth,
                    $firestore,
                    $userDocument,
                    $uid
                );

                if ($restored) {
                    return response()->json([
                        'message' => 'No fue posible eliminar la cuenta. El perfil fue restaurado y puedes continuar utilizando BluePass.',
                        'code' => 'ACCOUNT_DELETION_FAILED_RESTORED',
                    ], 500);
                }
            }

            // Si no se pudo confirmar el estado de la identidad
            // o la eliminacion ya se habia completado,
            // mantener el perfil pendiente de revision.
            if ($authDeletionStarted) {
                Log::warning(
                    'Eliminacion de cuenta pendiente de verificacion',
                    [
                        'uid' => $uid,
                        'authDeleted' => $authDeleted,
                    ]
                );

                return response()->json([
                    'message' => 'La eliminacion no pudo confirmarse completamente. La cuenta requiere una revision.',
                    'code' => 'ACCOUNT_DELETION_REVIEW_REQUIRED',
                ], 500);
            }

            return response()->json([
                'message' => 'No fue posible completar la eliminacion.',
            ], 500);
        }
    }

    private function hasRelatedRecords(
        FirestoreClient $firestore,
        DocumentReference $userDocument,
        string $uid
    ): bool {
        $collections = [
            'orders',
            'tickets',
            'notifications',
            'purchaseEmailOutbox',
        ];

        foreach ($collections as $name) {
            $documents = $firestore
                ->collection($name)
                ->where('userId', '=', $uid)
                ->limit(1)
                ->documents();

            if ($this->containsDocuments($documents)) {
                return true;
            }
        }

        // Comprobar reservas guardadas dentro de eventos.
        $reservations = $firestore
            ->collectionGroup('reservations')
            ->where('userId', '=', $uid)
            ->limit(1)
            ->documents();

        if ($this->containsDocuments($reservations)) {
            return true;
        }

        // Comprobar favoritos del comprador.
        $favorites = $userDocument
            ->collection('favorites')
            ->limit(1)
            ->documents();

        return $this->containsDocuments($favorites);
    }

    private function containsDocuments(iterable $documents): bool
    {
        foreach ($documents as $document) {
            if ($document->exists()) {
                return true;
            }
        }

        return false;
    }

    private function restoreAfterAuthFailure(
        Auth $auth,
        FirestoreClient $firestore,
        DocumentReference $userDocument,
        string $uid
    ): bool {
        try {
            // Consultar nuevamente Firebase Authentication.
            $firebaseUser = $auth->getUser($uid);

            // Verificar que sea la identidad esperada
            // y que no se encuentre deshabilitada.
            if (
                $firebaseUser->uid !== $uid ||
                $firebaseUser->disabled
            ) {
                Log::warning(
                    'La identidad no cumple las condiciones de restauracion',
                    ['uid' => $uid]
                );

                return false;
            }

            // Recuperar el perfil solamente si sigue bloqueado
            // y pertenece al mismo usuario.
            $restored = $this->restoreUser(
                $firestore,
                $userDocument,
                $uid
            );

            if ($restored) {
                Log::info(
                    'Cuenta restaurada despues de un error de Firebase Authentication',
                    ['uid' => $uid]
                );
            }

            return $restored;
        } catch (Throwable $exception) {
            // No restaurar si no se puede comprobar
            // el estado de Firebase Authentication.
            Log::warning(
                'No fue posible verificar la identidad despues del error de eliminacion',
                [
                    'uid' => $uid,
                    'exception' => get_class($exception),
                ]
            );

            return false;
        }
    }

    private function restoreUser(
        FirestoreClient $firestore,
        DocumentReference $userDocument,
        string $uid
    ): bool {
        try {
            return $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $userDocument,
                    $uid
                ): bool {
                    $snapshot = $transaction->snapshot($userDocument);

                    if (!$snapshot->exists()) {
                        return false;
                    }

                    $data = $snapshot->data();

                    if (
                        !is_array($data) ||
                        ($data['authSubject'] ?? null) !== $uid ||
                        ($data['role'] ?? null) !== 'customer' ||
                        ($data['status'] ?? null) !== 'deletion_pending'
                    ) {
                        return false;
                    }

                    $transaction->update($userDocument, [
                        [
                            'path' => 'status',
                            'value' => 'active',
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => FieldValue::serverTimestamp(),
                        ],
                    ]);

                    return true;
                }
            );
        } catch (Throwable $exception) {
            Log::error('Error al restaurar cuenta BluePass', [
                'uid' => $uid,
                'exception' => get_class($exception),
            ]);

            return false;
        }
    }
}
