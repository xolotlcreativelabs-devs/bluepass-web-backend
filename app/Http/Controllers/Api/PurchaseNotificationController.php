<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PurchaseNotificationController extends Controller
{
    private const PAGE_LIMIT = 20;

    /**
     * Consultar las notificaciones del comprador autenticado.
     */
    public function index(
        Request $request,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return $this->error(
                'Debes iniciar sesion para consultar notificaciones.',
                401
            );
        }

        if ($role !== 'customer') {
            return $this->error(
                'Solo los compradores pueden consultar sus notificaciones.',
                403
            );
        }

        $query = $request->query();

        // Solo se permite el cursor de paginacion.
        foreach (array_keys($query) as $parameter) {
            if ($parameter !== 'cursor') {
                return $this->error(
                    'La consulta contiene parametros no permitidos.',
                    422
                );
            }
        }

        $cursor = null;

        if (array_key_exists('cursor', $query)) {
            $cursor = $this->decodeCursor($query['cursor']);

            if ($cursor === null) {
                return $this->error(
                    'El cursor de paginacion no es valido.',
                    422
                );
            }
        }

        try {
            // Obtener las notificaciones propias del comprador.
            $documents = $firestore
                ->collection('notifications')
                ->where('userId', '=', $uid)
                ->documents();

            $notifications = [];

            foreach ($documents as $document) {
                if (!$document->exists()) {
                    continue;
                }

                $data = $document->data();

                if (
                    !is_array($data) ||
                    ($data['userId'] ?? null) !== $uid
                ) {
                    continue;
                }

                if (
                    ($data['channel'] ?? null) !== 'in_app' ||
                    ($data['type'] ?? null) !== 'purchase_confirmed'
                ) {
                    continue;
                }

                $status = $data['status'] ?? null;

                if (!in_array($status, ['read', 'unread'], true)) {
                    continue;
                }

                $createdAt = $data['createdAt'] ?? null;

                $notifications[] = [
                    'id' => $document->id(),
                    'orderId' => $data['orderId'] ?? null,
                    'type' => $data['type'],
                    'title' => $data['title'] ?? '',
                    'message' => $data['message'] ?? '',
                    'status' => $status,
                    'ticketCount' => $data['ticketCount'] ?? 0,
                    'createdAt' => $this->formatTimestamp(
                        $createdAt
                    ),
                    'readAt' => $this->formatTimestamp(
                        $data['readAt'] ?? null
                    ),
                    '_sortTime' => $this->timestampKey(
                        $createdAt
                    ),
                ];
            }

            // Contar todas las notificaciones propias sin leer.
            $unreadCount = count(
                array_filter(
                    $notifications,
                    static fn (array $notification): bool =>
                        $notification['status'] === 'unread'
                )
            );

            // Ordenar por fecha e identificador.
            // El identificador resuelve fechas iguales.
            usort(
                $notifications,
                static function (
                    array $first,
                    array $second
                ): int {
                    $timeComparison = strcmp(
                        $second['_sortTime'],
                        $first['_sortTime']
                    );

                    if ($timeComparison !== 0) {
                        return $timeComparison;
                    }

                    return strcmp(
                        $second['id'],
                        $first['id']
                    );
                }
            );

            // Continuar despues del ultimo elemento anterior.
            if ($cursor !== null) {
                $notifications = array_values(
                    array_filter(
                        $notifications,
                        static function (array $notification) use (
                            $cursor
                        ): bool {
                            $timeComparison = strcmp(
                                $notification['_sortTime'],
                                $cursor['time']
                            );

                            if ($timeComparison < 0) {
                                return true;
                            }

                            if ($timeComparison > 0) {
                                return false;
                            }

                            return strcmp(
                                $notification['id'],
                                $cursor['id']
                            ) < 0;
                        }
                    )
                );
            }

            $hasMore = count($notifications) > self::PAGE_LIMIT;

            $page = array_slice(
                $notifications,
                0,
                self::PAGE_LIMIT
            );

            $nextCursor = null;

            if ($hasMore && $page !== []) {
                $last = $page[count($page) - 1];

                $nextCursor = $this->encodeCursor(
                    $last['_sortTime'],
                    $last['id']
                );
            }

            // No exponer campos internos de ordenamiento.
            foreach ($page as &$notification) {
                unset($notification['_sortTime']);
            }

            unset($notification);

            return $this->withoutCache(
                response()->json([
                    'message' => 'Notificaciones consultadas correctamente.',
                    'notifications' => $page,
                    'count' => count($page),
                    'unreadCount' => $unreadCount,
                    'hasMore' => $hasMore,
                    'nextCursor' => $nextCursor,
                ])
            );
        } catch (Throwable $exception) {
            Log::error(
                'Error al consultar notificaciones BluePass',
                [
                    'exception' => get_class($exception),
                ]
            );

            return $this->error(
                'No fue posible consultar las notificaciones.',
                500
            );
        }
    }

    /**
     * Marcar como leida una notificacion propia.
     */
    public function markRead(
        Request $request,
        FirestoreClient $firestore,
        string $notificationId
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return $this->error(
                'Debes iniciar sesion para actualizar notificaciones.',
                401
            );
        }

        if ($role !== 'customer') {
            return $this->error(
                'Solo los compradores pueden actualizar sus notificaciones.',
                403
            );
        }

        if (
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $notificationId
            ) !== 1
        ) {
            return $this->error(
                'El identificador de la notificacion no es valido.',
                404
            );
        }

        // No permitir que se envien campos protegidos.
        if ($request->query() !== [] || $request->all() !== []) {
            return $this->error(
                'La solicitud contiene parametros no permitidos.',
                422
            );
        }

        try {
            $notificationDocument = $firestore
                ->collection('notifications')
                ->document($notificationId);

            $result = $firestore->runTransaction(
                function ($transaction) use (
                    $notificationDocument,
                    $uid,
                    $notificationId
                ): array {
                    $snapshot = $transaction->snapshot(
                        $notificationDocument
                    );

                    if (!$snapshot->exists()) {
                        return ['found' => false];
                    }

                    $data = $snapshot->data();

                    // Ocultar las notificaciones de otros compradores.
                    if (
                        !is_array($data) ||
                        ($data['userId'] ?? null) !== $uid ||
                        ($data['channel'] ?? null) !== 'in_app' ||
                        ($data['type'] ?? null) !== 'purchase_confirmed'
                    ) {
                        return ['found' => false];
                    }

                    $status = $data['status'] ?? null;

                    if (!in_array($status, ['read', 'unread'], true)) {
                        return ['found' => false];
                    }

                    // Mantener la fecha si ya estaba leida.
                    if ($status === 'read') {
                        return [
                            'found' => true,
                            'alreadyRead' => true,
                            'notificationId' => $notificationId,
                            'readAt' => $this->formatTimestamp(
                                $data['readAt'] ?? null
                            ),
                        ];
                    }

                    $timestamp = new Timestamp(
                        new DateTimeImmutable()
                    );

                    $transaction->update(
                        $notificationDocument,
                        [
                            [
                                'path' => 'status',
                                'value' => 'read',
                            ],
                            [
                                'path' => 'readAt',
                                'value' => $timestamp,
                            ],
                        ]
                    );

                    return [
                        'found' => true,
                        'alreadyRead' => false,
                        'notificationId' => $notificationId,
                        'readAt' => $this->formatTimestamp(
                            $timestamp
                        ),
                    ];
                }
            );

            if (($result['found'] ?? false) !== true) {
                return $this->error(
                    'La notificacion no existe.',
                    404
                );
            }

            return $this->withoutCache(
                response()->json([
                    'message' => 'Notificacion marcada como leida.',
                    'notification' => [
                        'id' => $result['notificationId'],
                        'status' => 'read',
                        'readAt' => $result['readAt'],
                    ],
                    'alreadyRead' => $result['alreadyRead'],
                ])
            );
        } catch (Throwable $exception) {
            Log::error(
                'Error al marcar una notificacion como leida',
                [
                    'exception' => get_class($exception),
                ]
            );

            return $this->error(
                'No fue posible actualizar la notificacion.',
                500
            );
        }
    }

    /**
     * Obtener una fecha comparable para ordenar resultados.
     */
    private function timestampKey(mixed $value): string
    {
        if ($value instanceof Timestamp) {
            $value = $value->get();
        }

        if (!$value instanceof DateTimeInterface) {
            return '';
        }

        return $value->format('U.u');
    }

    /**
     * Crear el cursor de la siguiente pagina.
     */
    private function encodeCursor(
        string $time,
        string $id
    ): string {
        $json = json_encode(
            [
                'time' => $time,
                'id' => $id,
            ],
            JSON_THROW_ON_ERROR
        );

        return rtrim(
            strtr(
                base64_encode($json),
                '+/',
                '-_'
            ),
            '='
        );
    }

    /**
     * Validar el cursor recibido.
     */
    private function decodeCursor(mixed $value): ?array
    {
        if (
            !is_string($value) ||
            $value === '' ||
            strlen($value) > 512 ||
            preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1
        ) {
            return null;
        }

        $decoded = base64_decode(
            strtr($value, '-_', '+/'),
            true
        );

        if ($decoded === false) {
            return null;
        }

        try {
            $cursor = json_decode(
                $decoded,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            return null;
        }

        if (
            !is_array($cursor) ||
            count($cursor) !== 2 ||
            !isset($cursor['time'], $cursor['id']) ||
            !is_string($cursor['time']) ||
            !is_string($cursor['id'])
        ) {
            return null;
        }

        if (
            $cursor['time'] !== '' &&
            preg_match(
                '/^[0-9]{1,12}\.[0-9]{6}$/D',
                $cursor['time']
            ) !== 1
        ) {
            return null;
        }

        if (
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $cursor['id']
            ) !== 1
        ) {
            return null;
        }

        return $cursor;
    }

    /**
     * Convertir fechas de Firestore a formato ISO 8601.
     */
    private function formatTimestamp(mixed $value): ?string
    {
        if ($value instanceof Timestamp) {
            $value = $value->get();
        }

        if (!$value instanceof DateTimeInterface) {
            return null;
        }

        return $value->format(DateTimeInterface::ATOM);
    }

    /**
     * Preparar respuestas de error.
     */
    private function error(
        string $message,
        int $status
    ): JsonResponse {
        return $this->withoutCache(
            response()->json([
                'message' => $message,
            ], $status)
        );
    }

    /**
     * Evitar almacenar datos privados en cache.
     */
    private function withoutCache(
        JsonResponse $response
    ): JsonResponse {
        $response->headers->set(
            'Cache-Control',
            'private, no-store, no-cache, must-revalidate'
        );

        $response->headers->set(
            'Pragma',
            'no-cache'
        );

        $response->headers->set(
            'X-Content-Type-Options',
            'nosniff'
        );

        return $response;
    }
}
