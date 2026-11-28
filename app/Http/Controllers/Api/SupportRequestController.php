<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class SupportRequestController extends Controller
{
    /**
     * Registrar una solicitud de soporte del comprador.
     */
    public function store(
        Request $request,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '' || $role !== 'customer') {
            return response()->json([
                'message' => 'No tienes permisos para registrar solicitudes de soporte.',
            ], is_string($uid) && $uid !== '' ? 403 : 401);
        }

        // No permitir parametros adicionales en la URL.
        if ($request->query() !== []) {
            return response()->json([
                'message' => 'La solicitud contiene parametros no permitidos.',
            ], 422);
        }

        // Impedir que el comprador modifique campos internos.
        $allowedFields = [
            'category',
            'description',
            'orderId',
        ];

        $input = $request->all();

        if (array_diff(array_keys($input), $allowedFields)) {
            return response()->json([
                'message' => 'La solicitud contiene campos no permitidos.',
            ], 422);
        }

        $validator = Validator::make($input, [
            'category' => [
                'required',
                'string',
                Rule::in([
                    'purchases',
                    'payments',
                    'tickets',
                    'account',
                    'other',
                ]),
            ],
            'description' => [
                'required',
                'string',
                'min:15',
                'max:2000',
            ],
            'orderId' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/\A[A-Za-z0-9_-]{1,128}\z/',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la solicitud no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $description = trim($data['description']);
        $orderId = $data['orderId'] ?? null;

        if (mb_strlen($description) < 15) {
            return response()->json([
                'message' => 'La descripcion debe contener al menos 15 caracteres.',
            ], 422);
        }

        try {
            // Comprobar que el pedido pertenezca al comprador.
            if ($orderId !== null) {
                $orderSnapshot = $firestore
                    ->collection('orders')
                    ->document($orderId)
                    ->snapshot();

                if (!$orderSnapshot->exists()) {
                    return $this->orderNotFound();
                }

                $order = $orderSnapshot->data();

                if (
                    !is_array($order) ||
                    ($order['userId'] ?? null) !== $uid
                ) {
                    // No revelar si el pedido pertenece a otra persona.
                    return $this->orderNotFound();
                }
            }

            // Crear el documento con un identificador generado.
            $document = $firestore
                ->collection('support_requests')
                ->newDocument();

            $document->set([
                'userId' => $uid,
                'category' => $data['category'],
                'description' => $description,
                'orderId' => $orderId,
                'status' => 'pending',
                'createdAt' => FieldValue::serverTimestamp(),
                'updatedAt' => FieldValue::serverTimestamp(),
            ]);

            return response()->json([
                'message' => 'Solicitud de soporte registrada correctamente.',
                'supportRequest' => [
                    'id' => $document->id(),
                    'category' => $data['category'],
                    'orderId' => $orderId,
                    'status' => 'pending',
                ],
            ], 201);

        } catch (Throwable $exception) {
            Log::error('Error al registrar solicitud de soporte BluePass', [
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible registrar la solicitud de soporte.',
            ], 500);
        }
    }

    /**
     * Ocultar la existencia de pedidos ajenos.
     */
    private function orderNotFound(): JsonResponse
    {
        return response()->json([
            'message' => 'El pedido no existe.',
        ], 404);
    }
}
