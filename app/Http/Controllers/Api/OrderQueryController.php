<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PurchaseStatusMessageService;
use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderQueryController extends Controller
{
    private const PAGE_LIMIT = 50;

    /**
     * Consultar el historial del comprador autenticado.
     */
    public function index(
        Request $request,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $this->customerId($request);

        if ($uid === null) {
            return $this->unauthorized($request);
        }

        if ($request->query() !== []) {
            return response()->json([
                'message' => 'La consulta contiene parametros no permitidos.',
            ], 422);
        }

        try {
            $documents = $firestore
                ->collection('orders')
                ->where('userId', '=', $uid)
                ->limit(self::PAGE_LIMIT)
                ->documents();

            $orders = [];
            $groups = $this->emptyGroups();
            $eventCache = [];
            $now = new DateTimeImmutable();

            $messageService = app(
                PurchaseStatusMessageService::class
            );

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

                // Conservar el resumen original del pedido.
                $order = $this->formatOrder(
                    $document->id(),
                    $data,
                    $now
                );

                $orders[] = $order;

                // Clasificar el pedido dentro del historial.
                $history = $this->historyData(
                    $data,
                    $order['status'],
                    $firestore,
                    $eventCache,
                    $now
                );

                // El aviso utiliza el estado actualizado del resumen.
                $notification = $this->notificationData(
                    $data,
                    $order['status'],
                    $messageService
                );

                // Agregar informacion sin modificar orders.
                $groups[$history['group']][] = array_merge(
                    $order,
                    $history,
                    [
                        'notification' => $notification,
                    ]
                );
            }

            return response()->json([
                'message' => 'Pedidos consultados correctamente.',
                'orders' => $orders,
                'count' => count($orders),
                'groups' => $groups,
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al consultar pedidos BluePass', [
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar los pedidos.',
            ], 500);
        }
    }

    /**
     * Consultar el detalle de un pedido propio.
     */
    public function show(
        Request $request,
        string $orderId,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $this->customerId($request);

        if ($uid === null) {
            return $this->unauthorized($request);
        }

        if (
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $orderId
            ) !== 1
        ) {
            return $this->notFound();
        }

        try {
            $document = $firestore
                ->collection('orders')
                ->document($orderId)
                ->snapshot();

            if (!$document->exists()) {
                return $this->notFound();
            }

            $data = $document->data();

            // Ocultar los pedidos de otros compradores.
            if (
                !is_array($data) ||
                ($data['userId'] ?? null) !== $uid
            ) {
                return $this->notFound();
            }

            $now = new DateTimeImmutable();

            // El resumen mantiene el contrato anterior.
            $order = $this->formatOrder(
                $orderId,
                $data,
                $now
            );

            $eventCache = [];

            // Los datos adicionales van fuera de order.
            $history = $this->historyData(
                $data,
                $order['status'],
                $firestore,
                $eventCache,
                $now
            );

            $messageService = app(
                PurchaseStatusMessageService::class
            );

            $notification = $this->notificationData(
                $data,
                $order['status'],
                $messageService
            );

            return response()->json([
                'message' => 'Pedido consultado correctamente.',
                'order' => $order,
                'history' => $history,
                'notification' => $notification,
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al consultar detalle de pedido', [
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar el pedido.',
            ], 500);
        }
    }

    /**
     * Obtener la identidad de un comprador autorizado.
     */
    private function customerId(Request $request): ?string
    {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (
            !is_string($uid) ||
            $uid === '' ||
            $role !== 'customer'
        ) {
            return null;
        }

        return $uid;
    }

    /**
     * Responder a solicitudes sin permisos.
     */
    private function unauthorized(Request $request): JsonResponse
    {
        $uid = $request->attributes->get('firebase_uid');

        $status = is_string($uid) && $uid !== ''
            ? 403
            : 401;

        return response()->json([
            'message' => 'No tienes permisos para consultar pedidos.',
        ], $status);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'El pedido no existe.',
        ], 404);
    }

    /**
     * Preparar las secciones del historial.
     */
    private function emptyGroups(): array
    {
        return [
            'upcoming' => [],
            'ongoing' => [],
            'past' => [],
            'pending' => [],
            'cancelled' => [],
            'unknown' => [],
        ];
    }

    /**
     * Consultar un evento una sola vez por solicitud.
     */
    private function eventData(
        string $eventId,
        FirestoreClient $firestore,
        array &$eventCache
    ): ?array {
        if (array_key_exists($eventId, $eventCache)) {
            return $eventCache[$eventId];
        }

        if (
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $eventId
            ) !== 1
        ) {
            $eventCache[$eventId] = null;

            return null;
        }

        $document = $firestore
            ->collection('events')
            ->document($eventId)
            ->snapshot();

        if (!$document->exists()) {
            $eventCache[$eventId] = null;

            return null;
        }

        $event = $document->data();

        $eventCache[$eventId] = is_array($event)
            ? $event
            : null;

        return $eventCache[$eventId];
    }

    /**
     * Clasificar el pedido segun su estado y fechas.
     */
    private function classifyOrder(
        string $status,
        ?DateTimeImmutable $start,
        ?DateTimeImmutable $end,
        DateTimeImmutable $now
    ): string {
        if (in_array($status, ['cancelled', 'expired'], true)) {
            return 'cancelled';
        }

        if ($status === 'pending') {
            return 'pending';
        }

        if ($status !== 'paid') {
            return 'unknown';
        }

        if (
            $start === null ||
            $end === null ||
            $end <= $start
        ) {
            return 'unknown';
        }

        if ($now < $start) {
            return 'upcoming';
        }

        if ($now >= $end) {
            return 'past';
        }

        return 'ongoing';
    }

    /**
     * Preparar los datos adicionales del historial.
     */
    private function historyData(
        array $order,
        mixed $status,
        FirestoreClient $firestore,
        array &$eventCache,
        DateTimeImmutable $now
    ): array {
        $eventId = $order['eventId'] ?? null;
        $event = null;

        if (
            $status === 'paid' &&
            is_string($eventId) &&
            $eventId !== ''
        ) {
            $event = $this->eventData(
                $eventId,
                $firestore,
                $eventCache
            );
        }

        $start = $this->readTimestamp(
            $event['date'] ?? null
        );

        $end = $this->readTimestamp(
            $event['endAt'] ?? null
        );

        $group = $this->classifyOrder(
            is_string($status) ? $status : '',
            $start,
            $end,
            $now
        );

        $eventDetails = null;

        if ($event !== null) {
            $eventDetails = [
                'id' => $eventId,
                'name' => $event['name'] ?? null,
                'venue' => $event['venue'] ?? null,
                'timezone' => $event['timezone'] ?? null,
                'date' => $start?->format(
                    DateTimeInterface::ATOM
                ),
                'endAt' => $end?->format(
                    DateTimeInterface::ATOM
                ),
            ];
        }

        return [
            'group' => $group,
            'event' => $eventDetails,
            'createdAt' => $this->formatTimestamp(
                $order['createdAt'] ?? null
            ),
            'paidAt' => $this->formatTimestamp(
                $order['paidAt'] ?? null
            ),
        ];
    }

    /**
     * Preparar el aviso sin modificar los datos del pedido.
     */
    private function notificationData(
        array $data,
        mixed $status,
        PurchaseStatusMessageService $messageService
    ): array {
        $notificationOrder = $data;

        // Utilizar el estado calculado para reservas vencidas.
        $notificationOrder['status'] = $status;

        return $messageService->forOrder(
            $notificationOrder
        );
    }

    /**
     * Mantener el resumen original usado al crear el pedido.
     */
    private function formatOrder(
        string $orderId,
        array $order,
        DateTimeImmutable $now
    ): array {
        $expiresAt = $this->readTimestamp(
            $order['expiresAt'] ?? null
        );

        $status = $order['status'] ?? null;

        // Reflejar vencimientos aunque la tarea automatica
        // todavia no haya actualizado el pedido.
        if (
            $status === 'pending' &&
            $expiresAt !== null &&
            $now >= $expiresAt
        ) {
            $status = 'expired';
        }

        // Conservar compatibilidad con pedidos anteriores.
        $items = $order['items'] ?? null;

        if (!is_array($items)) {
            $items = [];

            if (
                isset(
                    $order['ticketTypeId'],
                    $order['quantity'],
                    $order['unitPrice'],
                    $order['subtotal']
                )
            ) {
                $items[] = [
                    'ticketTypeId' => $order['ticketTypeId'],
                    'quantity' => $order['quantity'],
                    'unitPrice' => $order['unitPrice'],
                    'subtotal' => $order['subtotal'],
                ];
            }
        }

        return [
            'id' => $orderId,
            'eventId' => $order['eventId'] ?? null,
            'sellerId' => $order['sellerId'] ?? null,
            'channel' => $order['channel'] ?? null,
            'items' => $items,
            'reservationId' => $order['reservationId'] ?? null,
            'ticketTypeId' => $order['ticketTypeId'] ?? null,
            'quantity' => $order['quantity'] ?? null,
            'unitPrice' => $order['unitPrice'] ?? null,
            'currency' => $order['currency'] ?? null,
            'subtotal' => $order['subtotal'] ?? null,
            'fees' => $order['fees'] ?? null,
            'total' => $order['total'] ?? null,
            'status' => $status,
            'buyer' => $order['buyer'] ?? null,
            'expiresAt' => $expiresAt?->format(
                DateTimeInterface::ATOM
            ),
        ];
    }

    /**
     * Convertir fechas de Firestore.
     */
    private function readTimestamp(
        mixed $value
    ): ?DateTimeImmutable {
        if ($value instanceof Timestamp) {
            $value = $value->get();
        }

        if (!$value instanceof DateTimeInterface) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value);
    }

    private function formatTimestamp(mixed $value): ?string
    {
        return $this->readTimestamp($value)?->format(
            DateTimeInterface::ATOM
        );
    }
}
