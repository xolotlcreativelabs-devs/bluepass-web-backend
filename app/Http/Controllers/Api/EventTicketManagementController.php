<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class EventTicketManagementController extends Controller
{
    /**
     * Crear un tipo de boleto para un evento.
     */
    public function store(
        Request $request,
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'No fue posible verificar la identidad.',
            ], 401);
        }

        if (!in_array($role, ['seller', 'admin'], true)) {
            return response()->json([
                'message' => 'No tienes permisos para crear tipos de boletos.',
            ], 403);
        }

        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) {
            return response()->json([
                'message' => 'El evento no existe.',
            ], 404);
        }

        $allowedFields = [
            'name',
            'description',
            'category',
            'price',
            'capacity',
            'saleStartAt',
            'saleEndAt',
        ];

        $unexpectedFields = array_diff(
            array_keys($request->all()),
            $allowedFields
        );

        if ($unexpectedFields !== []) {
            return response()->json([
                'message' => 'Se enviaron campos no autorizados.',
                'errors' => [
                    'fields' => array_values($unexpectedFields),
                ],
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'category' => [
                'required',
                'string',
                'in:general,vip,preferente,otro',
            ],
            'price' => [
                'required',
                'integer',
                'min:0',
                'max:10000000',
            ],
            'capacity' => [
                'required',
                'integer',
                'min:1',
                'max:1000000',
            ],
            'saleStartAt' => ['required', 'string', 'max:35'],
            'saleEndAt' => ['required', 'string', 'max:35'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos del boleto no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $data['name'] = trim($data['name']);

        if ($data['name'] === '') {
            return response()->json([
                'message' => 'Los datos del boleto no son válidos.',
                'errors' => [
                    'name' => ['El nombre no puede estar vacío.'],
                ],
            ], 422);
        }

        if (isset($data['description'])) {
            $data['description'] = trim($data['description']);
        }

        $saleStart = $this->parseDate($data['saleStartAt']);
        $saleEnd = $this->parseDate($data['saleEndAt']);

        $dateErrors = [];

        if ($saleStart === null) {
            $dateErrors['saleStartAt'] = [
                'Utiliza una fecha ISO 8601 con zona horaria.',
            ];
        }

        if ($saleEnd === null) {
            $dateErrors['saleEndAt'] = [
                'Utiliza una fecha ISO 8601 con zona horaria.',
            ];
        }

        if ($dateErrors !== []) {
            return $this->validationError($dateErrors);
        }

        if ($saleStart >= $saleEnd) {
            return $this->validationError([
                'saleEndAt' => [
                    'El cierre de venta debe ser posterior al inicio.',
                ],
            ]);
        }

        if ($saleEnd <= new DateTimeImmutable()) {
            return $this->validationError([
                'saleEndAt' => [
                    'El periodo de venta ya terminó.',
                ],
            ]);
        }

        try {
            $eventDocument = $firestore
                ->collection('events')
                ->document($id);

            $ticketDocument = $eventDocument
                ->collection('ticketTypes')
                ->newDocument();

            $timestamp = new Timestamp(new DateTimeImmutable());

            $ticket = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'],
                'price' => $data['price'],
                'currency' => 'MXN',
                'capacity' => $data['capacity'],
                'sold' => 0,
                'reserved' => 0,
                'saleStartAt' => new Timestamp($saleStart),
                'saleEndAt' => new Timestamp($saleEnd),
                'status' => 'active',
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
            ];

            $result = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $eventDocument,
                    $ticketDocument,
                    $ticket,
                    $data,
                    $saleStart,
                    $saleEnd,
                    $uid,
                    $role
                ): array {
                    $snapshot = $transaction->snapshot($eventDocument);

                    if (!$snapshot->exists()) {
                        return [
                            'code' => 404,
                            'message' => 'El evento no existe.',
                        ];
                    }

                    $event = $snapshot->data();

                    if (!is_array($event)) {
                        return [
                            'code' => 422,
                            'message' => 'Los datos del evento no son válidos.',
                        ];
                    }

                    if (
                        $role === 'seller' &&
                        ($event['sellerId'] ?? null) !== $uid
                    ) {
                        return [
                            'code' => 403,
                            'message' => 'No tienes permisos para modificar este evento.',
                        ];
                    }

                    $timezoneName = $event['timezone'] ?? null;
                    $eventStart = $this->eventDate($event['date'] ?? null);

                    if (
                        !is_string($timezoneName) ||
                        !in_array(
                            $timezoneName,
                            DateTimeZone::listIdentifiers(),
                            true
                        ) ||
                        $eventStart === null
                    ) {
                        return [
                            'code' => 422,
                            'message' => 'El evento necesita una fecha y zona horaria válidas.',
                        ];
                    }

                    $timezone = new DateTimeZone($timezoneName);

                    if (
                        $saleStart->format('P') !==
                            $saleStart->setTimezone($timezone)->format('P') ||
                        $saleEnd->format('P') !==
                            $saleEnd->setTimezone($timezone)->format('P')
                    ) {
                        return [
                            'code' => 422,
                            'message' => 'El periodo de venta no coincide con la zona horaria del evento.',
                        ];
                    }

                    if ($saleEnd > $eventStart) {
                        return [
                            'code' => 422,
                            'message' => 'El cierre de venta no puede superar el inicio del evento.',
                        ];
                    }

                    $transaction->create($ticketDocument, $ticket);

                    return [
                        'code' => 201,
                        'message' => 'Tipo de boleto creado correctamente.',
                        'ticketType' => [
                            'id' => $ticketDocument->id(),
                            'name' => $ticket['name'],
                            'description' => $ticket['description'],
                            'category' => $ticket['category'],
                            'price' => $ticket['price'],
                            'currency' => $ticket['currency'],
                            'capacity' => $ticket['capacity'],
                            'sold' => 0,
                            'reserved' => 0,
                            'saleStartAt' => $data['saleStartAt'],
                            'saleEndAt' => $data['saleEndAt'],
                            'status' => 'active',
                        ],
                    ];
                }
            );

            $statusCode = $result['code'] ?? 500;
            unset($result['code']);

            return response()->json($result, $statusCode);
        } catch (Throwable $e) {
            Log::error('Error al crear tipo de boleto BluePass', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible crear el tipo de boleto.',
            ], 500);
        }
    }

    /**
     * Leer una fecha ISO 8601 con su desplazamiento horario.
     */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:sP',
            $value
        );

        if (
            $date === false ||
            $date->format('Y-m-d\TH:i:sP') !== $value
        ) {
            return null;
        }

        return $date;
    }

    /**
     * Convertir la fecha del evento a un objeto comparable.
     */
    private function eventDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof Timestamp) {
            $value = $value->get();
        }

        if (!$value instanceof DateTimeInterface) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value);
    }

    /**
     * Responder cuando los datos no cumplen las reglas.
     */
    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'message' => 'Los datos del boleto no son válidos.',
            'errors' => $errors,
        ], 422);
    }
}