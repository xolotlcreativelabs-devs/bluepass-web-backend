<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderReceiptController extends Controller
{
    /**
     * Consultar el comprobante de un pedido pagado.
     */
    public function show(
        Request $request,
        string $orderId,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return $this->respond([
                'message' => 'Debes iniciar sesion para consultar comprobantes.',
            ], 401);
        }

        if ($role !== 'customer') {
            return $this->respond([
                'message' => 'Solo los compradores pueden consultar comprobantes.',
            ], 403);
        }

        if (
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $orderId
            ) !== 1
        ) {
            return $this->notFound();
        }

        if ($request->query() !== []) {
            return $this->respond([
                'message' => 'La consulta contiene parametros no permitidos.',
            ], 422);
        }

        try {
            $document = $firestore
                ->collection('orders')
                ->document($orderId)
                ->snapshot();

            if (!$document->exists()) {
                return $this->notFound();
            }

            $order = $document->data();

            // No revelar pedidos de otros compradores.
            if (
                !is_array($order) ||
                ($order['userId'] ?? null) !== $uid
            ) {
                return $this->notFound();
            }

            // Solo los pedidos pagados tienen comprobante.
            if (($order['status'] ?? null) !== 'paid') {
                return $this->respond([
                    'message' => 'El comprobante no esta disponible porque el pedido no esta pagado.',
                ], 409);
            }

            // Revisar que los importes coincidan con el pedido.
            if (!$this->validAmounts($order)) {
                Log::warning(
                    'Pedido pagado con importes inconsistentes',
                    ['orderId' => $orderId]
                );

                return $this->respond([
                    'message' => 'El comprobante no esta disponible por una inconsistencia en el pedido.',
                ], 409);
            }

            $paidAt = $this->formatTimestamp(
                $order['paidAt'] ?? null
            );

            if ($paidAt === null) {
                Log::warning(
                    'Pedido pagado sin fecha de pago',
                    ['orderId' => $orderId]
                );

                return $this->respond([
                    'message' => 'El comprobante no tiene una fecha de pago valida.',
                ], 409);
            }

            $buyer = $order['buyer'] ?? [];

            if (!is_array($buyer)) {
                $buyer = [];
            }

            $receipt = [
                'orderId' => $orderId,
                'eventId' => $order['eventId'] ?? null,
                'sellerId' => $order['sellerId'] ?? null,
                'channel' => $order['channel'] ?? null,
                'status' => 'paid',
                'buyer' => [
                    'name' => is_string($buyer['name'] ?? null)
                        ? $buyer['name']
                        : '',
                    'email' => is_string($buyer['email'] ?? null)
                        ? $buyer['email']
                        : '',
                ],
                'items' => $this->formatItems($order),
                'quantity' => $order['quantity'],
                'currency' => $order['currency'],
                'subtotal' => $order['subtotal'],
                'fees' => $order['fees'],
                'total' => $order['total'],
                'createdAt' => $this->formatTimestamp(
                    $order['createdAt'] ?? null
                ),
                'paidAt' => $paidAt,
            ];

            return $this->respond([
                'message' => 'Comprobante consultado correctamente.',
                'receipt' => $receipt,
            ]);
        } catch (Throwable $exception) {
            Log::error(
                'Error al consultar comprobante BluePass',
                [
                    'exception' => get_class($exception),
                ]
            );

            return $this->respond([
                'message' => 'No fue posible consultar el comprobante.',
            ], 500);
        }
    }

    /**
     * Comprobar los importes del pedido.
     */
    private function validAmounts(array $order): bool
    {
        $quantity = $order['quantity'] ?? null;
        $subtotal = $order['subtotal'] ?? null;
        $fees = $order['fees'] ?? null;
        $total = $order['total'] ?? null;
        $currency = $order['currency'] ?? null;
        $items = $order['items'] ?? null;

        if (
            !is_int($quantity) ||
            $quantity < 1 ||
            $quantity > 10 ||
            !is_int($subtotal) ||
            $subtotal < 0 ||
            !is_int($fees) ||
            $fees < 0 ||
            !is_int($total) ||
            $total < 0 ||
            $currency !== 'MXN' ||
            !is_array($items) ||
            $items === []
        ) {
            return false;
        }

        if ($subtotal + $fees !== $total) {
            return false;
        }

        $calculatedQuantity = 0;
        $calculatedSubtotal = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                return false;
            }

            $itemQuantity = $item['quantity'] ?? null;
            $unitPrice = $item['unitPrice'] ?? null;
            $itemSubtotal = $item['subtotal'] ?? null;
            $ticketTypeId = $item['ticketTypeId'] ?? null;

            if (
                !is_string($ticketTypeId) ||
                $ticketTypeId === '' ||
                !is_int($itemQuantity) ||
                $itemQuantity < 1 ||
                !is_int($unitPrice) ||
                $unitPrice < 0 ||
                !is_int($itemSubtotal) ||
                $itemSubtotal < 0
            ) {
                return false;
            }

            if (
                $unitPrice > 100000000 ||
                $itemSubtotal !== $itemQuantity * $unitPrice
            ) {
                return false;
            }

            $calculatedQuantity += $itemQuantity;
            $calculatedSubtotal += $itemSubtotal;
        }

        return $calculatedQuantity === $quantity &&
            $calculatedSubtotal === $subtotal;
    }

    /**
     * Preparar los boletos que aparecen en el comprobante.
     */
    private function formatItems(array $order): array
    {
        $items = [];

        foreach ($order['items'] as $item) {
            $items[] = [
                'ticketTypeId' => $item['ticketTypeId'],
                'quantity' => $item['quantity'],
                'unitPrice' => $item['unitPrice'],
                'subtotal' => $item['subtotal'],
            ];
        }

        return $items;
    }

    /**
     * Convertir fechas de Firestore al formato ISO 8601.
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
     * No revelar si un pedido pertenece a otra persona.
     */
    private function notFound(): JsonResponse
    {
        return $this->respond([
            'message' => 'El pedido no existe.',
        ], 404);
    }

    /**
     * Evitar que se almacenen datos personales en cache.
     */
    private function respond(
        array $data,
        int $status = 200
    ): JsonResponse {
        $response = response()->json($data, $status);

        $response->headers->set(
            'Cache-Control',
            'private, no-store, no-cache, max-age=0'
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
