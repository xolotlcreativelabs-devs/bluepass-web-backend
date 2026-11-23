<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class EventController extends Controller
{
    /**
     * Registrar un evento como borrador.
     */
    public function store(
        Request $request,
        FirestoreClient $firestore,
        AuditLogger $auditLogger
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
                'message' => 'No tienes permisos para crear eventos.',
            ], 403);
        }

        // Comprobar los campos permitidos.
        $allowedFields = [
            'name',
            'description',
            'category',
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
            'category' => ['required', 'string', 'min:2', 'max:80'],
            'venue' => ['required', 'string', 'min:3', 'max:250'],
            'timezone' => ['required', 'timezone'],
            'date' => [
                'nullable',
                'required_with:endAt',
                'date_format:Y-m-d\TH:i:sP',
            ],
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

        // Limpiar los campos de texto.
        foreach (['name', 'description', 'category', 'venue'] as $field) {
            $data[$field] = trim($data[$field]);

            if ($data[$field] === '') {
                return response()->json([
                    'message' => 'Los datos del evento no son válidos.',
                    'errors' => [
                        $field => ['Este campo no puede estar vacío.'],
                    ],
                ], 422);
            }
        }

        try {
            // Preparar las referencias antes de la transacción.
            $document = $firestore
                ->collection('events')
                ->newDocument();

            $auditDocument = $firestore
                ->collection('audit_logs')
                ->newDocument();

            // Preparar los datos del evento.
            $event = [
                'sellerId' => $uid,
                'name' => $data['name'],
                'description' => $data['description'],
                'category' => $data['category'],
                'venue' => $data['venue'],
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

            $eventId = $document->id();

            // Preparar la auditoría con el servicio centralizado.
            $auditData = $auditLogger->prepare(
                'created',
                'events',
                $eventId,
                $uid,
                $role,
                array_keys($data)
            );

            if ($auditData === null) {
                throw new RuntimeException(
                    'No fue posible preparar la auditoría.'
                );
            }

            // Guardar el evento y la auditoría en una transacción.
            $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $document,
                    $auditDocument,
                    $event,
                    $auditData
                ): void {
                    $transaction->create($document, $event);

                    $transaction->create(
                        $auditDocument,
                        $auditData
                    );
                }
            );

            return response()->json([
                'message' => 'Evento registrado como borrador.',
                'event' => [
                    'id' => $eventId,
                    'sellerId' => $uid,
                    'name' => $event['name'],
                    'description' => $event['description'],
                    'category' => $event['category'],
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
        FirestoreClient $firestore,
        AuditLogger $auditLogger
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
                'message' => 'No tienes permisos para editar eventos.',
            ], 403);
        }

        // Comprobar los campos permitidos.
        $allowedFields = [
            'name',
            'description',
            'category',
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

        // Validar únicamente los campos enviados.
        $validator = Validator::make($request->all(), [
            'name' => [
                'sometimes', 'required', 'string', 'min:3', 'max:150',
            ],
            'description' => [
                'sometimes', 'required', 'string', 'min:10', 'max:5000',
            ],
            'category' => [
                'sometimes', 'required', 'string', 'min:2', 'max:80',
            ],
            'venue' => [
                'sometimes', 'required', 'string', 'min:3', 'max:250',
            ],
            'timezone' => ['sometimes', 'required', 'timezone'],
            'date' => [
                'sometimes', 'required', 'date_format:Y-m-d\TH:i:sP',
            ],
            'endAt' => [
                'sometimes', 'required', 'date_format:Y-m-d\TH:i:sP',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos del evento no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Limpiar los campos de texto enviados.
        foreach (['name', 'description', 'category', 'venue'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = trim($data[$field]);

                if ($data[$field] === '') {
                    return response()->json([
                        'message' => 'Los datos del evento no son válidos.',
                        'errors' => [
                            $field => ['Este campo no puede estar vacío.'],
                        ],
                    ], 422);
                }
            }
        }

        try {
            // Buscar el documento del evento.
            $document = $firestore
                ->collection('events')
                ->document($id);

            // Comprobar permisos y fechas con los datos actuales.
            $result = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $firestore,
                    $document,
                    $id,
                    $uid,
                    $role,
                    $data,
                    $auditLogger
                ): array {
                    $snapshot = $transaction->snapshot($document);

                    if (!$snapshot->exists()) {
                        return [
                            'code' => 404,
                            'message' => 'El evento no existe.',
                        ];
                    }

                    $event = $snapshot->data();

                    if (!is_array($event)) {
                        return [
                            'code' => 500,
                            'message' => 'No fue posible obtener el evento.',
                        ];
                    }

                    // Comprobar la propiedad del evento.
                    if (
                        $role === 'seller' &&
                        ($event['sellerId'] ?? null) !== $uid
                    ) {
                        return [
                            'code' => 403,
                            'message' =>
                                'No tienes permisos para editar este evento.',
                        ];
                    }

                    // Obtener las fechas nuevas o existentes.
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

                    // Evitar una finalización anterior al inicio.
                    if (
                        $start !== null &&
                        $end !== null &&
                        new DateTimeImmutable($end)
                            <= new DateTimeImmutable($start)
                    ) {
                        return [
                            'code' => 422,
                            'message' => 'Los datos del evento no son válidos.',
                            'errors' => [
                                'endAt' => [
                                    'La finalización debe ser posterior al inicio.',
                                ],
                            ],
                        ];
                    }

                    // Preparar únicamente los campos modificados.
                    $updates = [];

                    foreach ($data as $field => $value) {
                        if (in_array($field, ['date', 'endAt'], true)) {
                            $value = new Timestamp(
                                new DateTimeImmutable($value)
                            );
                        }

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

                    // Preparar la auditoría con el servicio centralizado.
                    $auditData = $auditLogger->prepare(
                        'updated',
                        'events',
                        $id,
                        $uid,
                        $role,
                        array_keys($data)
                    );

                    if ($auditData === null) {
                        throw new RuntimeException(
                            'No fue posible preparar la auditoría.'
                        );
                    }

                    $auditDocument = $firestore
                        ->collection('audit_logs')
                        ->newDocument();

                    // Actualizar el evento.
                    $transaction->update($document, $updates);

                    // Guardar la auditoría en la misma transacción.
                    $transaction->create(
                        $auditDocument,
                        $auditData
                    );

                    return [
                        'code' => 200,
                        'message' => 'Evento actualizado correctamente.',
                        'event' => array_merge(
                            [
                                'id' => $id,
                                'sellerId' => $event['sellerId'] ?? null,
                                'status' => $event['status'] ?? null,
                            ],
                            $data
                        ),
                    ];
                }
            );

            $statusCode = $result['code'] ?? 500;

            unset($result['code']);

            return response()->json(
                $result,
                $statusCode
            );

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
