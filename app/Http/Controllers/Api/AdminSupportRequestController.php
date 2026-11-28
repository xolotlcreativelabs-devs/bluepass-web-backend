<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class AdminSupportRequestController extends Controller
{
    /**
     * Consultar las solicitudes de soporte.
     */
    public function index(
        Request $request,
        FirestoreClient $firestore
    ): JsonResponse {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized($request);
        }

        $parameters = $request->query();

        if (array_diff(array_keys($parameters), ['limit'])) {
            return response()->json([
                'message' => 'La consulta contiene parametros no permitidos.',
            ], 422);
        }

        $validator = Validator::make($parameters, [
            'limit' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:50',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los parametros de consulta no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 20);

        try {
            $documents = $firestore
                ->collection('support_requests')
                ->orderBy('createdAt', 'DESCENDING')
                ->limit($limit)
                ->documents();

            $requests = [];

            foreach ($documents as $document) {
                if (!$document->exists()) {
                    continue;
                }

                $data = $document->data();

                if (!$this->isValidRequest($data)) {
                    continue;
                }

                $requests[] = $this->formatRequest(
                    $document->id(),
                    $data
                );
            }

            return response()->json([
                'message' => 'Solicitudes de soporte consultadas correctamente.',
                'requests' => $requests,
                'count' => count($requests),
            ], 200);

        } catch (Throwable $exception) {
            Log::error(
                'Error al consultar solicitudes de soporte BluePass',
                [
                    'exception' => get_class($exception),
                ]
            );

            return response()->json([
                'message' => 'No fue posible consultar las solicitudes de soporte.',
            ], 500);
        }
    }

    /**
     * Consultar el detalle de una solicitud de soporte.
     */
    public function show(
        Request $request,
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized($request);
        }

        if ($request->query() !== []) {
            return response()->json([
                'message' => 'La consulta contiene parametros no permitidos.',
            ], 422);
        }

        if (preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $id) !== 1) {
            return $this->notFound();
        }

        try {
            $document = $firestore
                ->collection('support_requests')
                ->document($id)
                ->snapshot();

            if (!$document->exists()) {
                return $this->notFound();
            }

            $data = $document->data();

            if (!$this->isValidRequest($data)) {
                return $this->notFound();
            }

            return response()->json([
                'message' => 'Solicitud de soporte consultada correctamente.',
                'supportRequest' => $this->formatRequest(
                    $id,
                    $data
                ),
            ], 200);

        } catch (Throwable $exception) {
            Log::error(
                'Error al consultar detalle de soporte BluePass',
                [
                    'exception' => get_class($exception),
                ]
            );

            return response()->json([
                'message' => 'No fue posible consultar la solicitud de soporte.',
            ], 500);
        }
    }

    /**
     * Verificar la identidad del administrador.
     */
    private function isAdmin(Request $request): bool
    {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        return is_string($uid)
            && $uid !== ''
            && $role === 'admin';
    }

    /**
     * Rechazar solicitudes sin autorizacion.
     */
    private function unauthorized(Request $request): JsonResponse
    {
        $uid = $request->attributes->get('firebase_uid');

        $status = is_string($uid) && $uid !== ''
            ? 403
            : 401;

        return response()->json([
            'message' => 'No tienes permisos para consultar solicitudes de soporte.',
        ], $status);
    }

    /**
     * Verificar los datos minimos de una incidencia.
     */
    private function isValidRequest(mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        return is_string($data['userId'] ?? null)
            && $data['userId'] !== ''
            && is_string($data['category'] ?? null)
            && $data['category'] !== ''
            && is_string($data['description'] ?? null)
            && $data['description'] !== ''
            && in_array(
                $data['status'] ?? null,
                ['pending', 'in_progress', 'resolved', 'closed'],
                true
            );
    }

    /**
     * Preparar los datos que puede consultar el administrador.
     */
    private function formatRequest(
        string $id,
        array $data
    ): array {
        return [
            'id' => $id,
            'userId' => $data['userId'],
            'category' => $data['category'],
            'description' => $data['description'],
            'orderId' => $data['orderId'] ?? null,
            'status' => $data['status'],
            'createdAt' => $this->formatTimestamp(
                $data['createdAt'] ?? null
            ),
            'updatedAt' => $this->formatTimestamp(
                $data['updatedAt'] ?? null
            ),
        ];
    }

    /**
     * Convertir las fechas de Firestore a ISO 8601.
     */
    private function formatTimestamp(mixed $value): ?string
    {
        if ($value instanceof Timestamp) {
            $value = $value->get();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return null;
    }

    /**
     * Responder cuando no existe la solicitud.
     */
    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'La solicitud de soporte no existe.',
        ], 404);
    }
}
