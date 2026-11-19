<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class EventController extends Controller
{
    /**
     * Registrar un evento como borrador.
     */
    public function store(
        Request $request,
        FirestoreClient $firestore
    ): JsonResponse {
        // Obtener el usuario autenticado.
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'No fue posible verificar la identidad.',
            ], 401);
        }

        if (!in_array($role, ['seller', 'admin'], true)) {
            return response()->json([
                'message' => 'No tienes permisos para crear eventos.',
            ], 403);
        }

        // Evitar cambios en campos protegidos.
        $allowedFields = [
            'name',
            'description',
            'venue',
            'date',
            'endAt',
            'timezone',
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

        // Validar los datos del evento.
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'venue' => ['required', 'string', 'min:3', 'max:250'],
            'timezone' => ['required', 'timezone'],
            'date' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'endAt' => [
                'nullable',
                'required_with:date',
                'date_format:Y-m-d\TH:i:sP',
                'after:date',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos del evento no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            // Crear el evento en Firestore.
            $document = $firestore
                ->collection('events')
                ->newDocument();

            $event = [
                'sellerId' => $uid,
                'name' => trim($data['name']),
                'description' => trim($data['description']),
                'venue' => trim($data['venue']),
                'timezone' => $data['timezone'],
                'imageUrl' => null,
                'date' => isset($data['date'])
                    ? new Timestamp(new DateTimeImmutable($data['date']))
                    : null,
                'endAt' => isset($data['endAt'])
                    ? new Timestamp(new DateTimeImmutable($data['endAt']))
                    : null,
                'status' => 'draft',
                'createdAt' => FieldValue::serverTimestamp(),
                'updatedAt' => FieldValue::serverTimestamp(),
            ];

            $document->set($event);

            return response()->json([
                'message' => 'Evento registrado como borrador.',
                'event' => [
                    'id' => $document->id(),
                    'sellerId' => $uid,
                    'name' => $event['name'],
                    'description' => $event['description'],
                    'venue' => $event['venue'],
                    'timezone' => $event['timezone'],
                    'date' => $data['date'] ?? null,
                    'endAt' => $data['endAt'] ?? null,
                    'status' => 'draft',
                ],
            ], 201);

        } catch (Throwable $e) {
            Log::error('Error al registrar evento BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible registrar el evento.',
            ], 500);
        }
    }

    /**
     * Editar un evento existente.
     */
    public function update(
        Request $request,
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        // Obtener el usuario autenticado.
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'No fue posible verificar la identidad.',
            ], 401);
        }

        if (!in_array($role, ['seller', 'admin'], true)) {
            return response()->json([
                'message' => 'No tienes permisos para editar eventos.',
            ], 403);
        }

        // Permitir únicamente campos editables.
        $allowedFields = [
            'name',
            'description',
            'venue',
            'date',
            'endAt',
            'timezone',
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

        // Validar los campos enviados.
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:150'],
            'description' => [
                'sometimes', 'required', 'string', 'min:10', 'max:5000',
            ],
            'venue' => ['sometimes', 'required', 'string', 'min:3', 'max:250'],
            'timezone' => ['sometimes', 'required', 'timezone'],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d\TH:i:sP'],
            'endAt' => ['sometimes', 'required', 'date_format:Y-m-d\TH:i:sP'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos del evento no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            // Buscar el evento en Firestore.
            $document = $firestore
                ->collection('events')
                ->document($id);

            $snapshot = $document->snapshot();

            if (!$snapshot->exists()) {
                return response()->json([
                    'message' => 'El evento no existe.',
                ], 404);
            }

            $event = $snapshot->data();

            if (!is_array($event)) {
                return response()->json([
                    'message' => 'No fue posible obtener el evento.',
                ], 500);
            }

            // El vendedor solo puede editar sus eventos.
            if (
                $role === 'seller' &&
                ($event['sellerId'] ?? null) !== $uid
            ) {
                return response()->json([
                    'message' => 'No tienes permisos para editar este evento.',
                ], 403);
            }

            // Comprobar la coherencia de las fechas.
            $start = $data['date'] ?? ($event['date'] ?? null);
            $end = $data['endAt'] ?? ($event['endAt'] ?? null);

            if ($start instanceof Timestamp) {
                $start = $start->get();
            }

            if ($end instanceof Timestamp) {
                $end = $end->get();
            }

            if ($start instanceof DateTimeInterface) {
                $start = $start->format(DateTimeInterface::ATOM);
            }

            if ($end instanceof DateTimeInterface) {
                $end = $end->format(DateTimeInterface::ATOM);
            }

            if (
                $start !== null &&
                $end !== null &&
                new DateTimeImmutable($end) <= new DateTimeImmutable($start)
            ) {
                return response()->json([
                    'message' => 'Los datos del evento no son válidos.',
                    'errors' => [
                        'endAt' => [
                            'La finalización debe ser posterior al inicio.',
                        ],
                    ],
                ], 422);
            }

            // Preparar únicamente los campos enviados.
            $updates = [];
            $updatedFields = [];

            foreach ($data as $field => $value) {
                if (in_array($field, ['name', 'description', 'venue'], true)) {
                    $value = trim($value);
                }

                if (in_array($field, ['date', 'endAt'], true)) {
                    $updatedFields[$field] = $value;
                    $value = new Timestamp(new DateTimeImmutable($value));
                } else {
                    $updatedFields[$field] = $value;
                }

                $updates[] = [
                    'path' => $field,
                    'value' => $value,
                ];
            }

            // Registrar la fecha de modificación.
            $updates[] = [
                'path' => 'updatedAt',
                'value' => FieldValue::serverTimestamp(),
            ];

            $document->update($updates);

            return response()->json([
                'message' => 'Evento actualizado correctamente.',
                'event' => array_merge(
                    [
                        'id' => $id,
                        'sellerId' => $event['sellerId'] ?? null,
                        'status' => $event['status'] ?? null,
                    ],
                    $updatedFields
                ),
            ], 200);

        } catch (Throwable $e) {
            Log::error('Error al actualizar evento BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible actualizar el evento.',
            ], 500);
        }
    }
}
