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

class OrderQueryController extends Controller
{
    private const PAGE_LIMIT = 50;

    /**
     * Consultar los pedidos del comprador autenticado.
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

                $orders[] = $this->formatOrder(
                    $document->id(),
                    $data
                );
            }

            return response()->json([
                'message' => 'Pedidos consultados correctamente.',
                'orders' => $orders,
                'count' => count($orders),
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
            !preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $orderId
            )
        ) {
            return response()->json([
                'message' => 'El pedido no existe.',
            ], 404);
        }

        try {
            $document = $firestore
                ->collection('orders')
                ->document($orderId)
                ->snapshot();

            if (!$document->exists()) {
                return response()->json([
                    'message' => 'El pedido no existe.',
                ], 404);
            }

            $data = $document->data();

            // No revelar pedidos pertenecientes a otros compradores.
            if (
                !is_array($data) ||
                ($data['userId'] ?? null) !== $uid
            ) {
                return response()->json([
                    'message' => 'El pedido no existe.',
                ], 404);
            }

            return response()->json([
                'message' => 'Pedido consultado correctamente.',
                'order' => $this->formatOrder(
                    $orderId,
                    $data
                ),
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

        if (!is_string($uid) || $uid === '') {
            return null;
        }

        if ($role !== 'customer') {
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

    /**
     * Preparar el resumen que puede ver el comprador.
     */
    private function formatOrder(
        string $orderId,
        array $order
    ): array {
        $expiresAt = $this->readTimestamp(
            $order['expiresAt'] ?? null
        );

        $status = $order['status'] ?? null;

        // Mostrar vencimiento efectivo aunque el proceso automatico
        // todavia no haya actualizado el documento.
        if (
            $status === 'pending' &&
            $expiresAt !== null &&
            new DateTimeImmutable() >= $expiresAt
        ) {
            $status = 'expired';
        }

        // Mantener compatibilidad con pedidos anteriores.
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
     * Convertir las fechas de Firestore.
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
}
