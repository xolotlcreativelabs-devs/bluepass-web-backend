<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TicketQrImageService;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class TicketPrintController extends Controller
{
    /**
     * Preparar la version imprimible de un boleto.
     */
    public function show(
        Request $request,
        string $ticketId,
        FirestoreClient $firestore,
        TicketQrImageService $qrService
    ): Response {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return $this->error(
                'Debes iniciar sesion para consultar el boleto.',
                401
            );
        }

        if ($role !== 'customer') {
            return $this->error(
                'No tienes permisos para consultar el boleto.',
                403
            );
        }

        if ($request->query() !== []) {
            return $this->error(
                'La consulta contiene parametros no permitidos.',
                422
            );
        }

        if (!$this->validId($ticketId)) {
            return $this->notFound();
        }

        try {
            // Consultar el boleto solicitado.
            $ticketDocument = $firestore
                ->collection('tickets')
                ->document($ticketId)
                ->snapshot();

            if (!$ticketDocument->exists()) {
                return $this->notFound();
            }

            $ticket = $ticketDocument->data();

            // No revelar boletos de otros compradores.
            if (
                !is_array($ticket) ||
                ($ticket['userId'] ?? null) !== $uid
            ) {
                return $this->notFound();
            }

            // No imprimir boletos usados o revocados.
            if (($ticket['status'] ?? null) !== 'valid') {
                return $this->error(
                    'El boleto no esta disponible para imprimir.',
                    409
                );
            }

            $orderId = $ticket['orderId'] ?? null;
            $eventId = $ticket['eventId'] ?? null;
            $ticketTypeId = $ticket['ticketTypeId'] ?? null;

            if (
                !is_string($orderId) ||
                !is_string($eventId) ||
                !is_string($ticketTypeId) ||
                !$this->validId($orderId) ||
                !$this->validId($eventId) ||
                !$this->validId($ticketTypeId)
            ) {
                return $this->error(
                    'No fue posible verificar los datos del boleto.',
                    409
                );
            }

            // Verificar que el pedido realmente esta pagado.
            $orderDocument = $firestore
                ->collection('orders')
                ->document($orderId)
                ->snapshot();

            if (!$orderDocument->exists()) {
                return $this->error(
                    'No fue posible verificar la compra.',
                    409
                );
            }

            $order = $orderDocument->data();

            if (
                !is_array($order) ||
                ($order['userId'] ?? null) !== $uid ||
                ($order['eventId'] ?? null) !== $eventId ||
                ($order['status'] ?? null) !== 'paid' ||
                $this->formatTimestamp(
                    $order['paidAt'] ?? null
                ) === null
            ) {
                return $this->error(
                    'La compra no tiene una confirmacion de pago valida.',
                    409
                );
            }

            // Consultar la informacion del evento.
            $eventDocument = $firestore
                ->collection('events')
                ->document($eventId)
                ->snapshot();

            if (!$eventDocument->exists()) {
                return $this->error(
                    'La informacion del evento no esta disponible.',
                    409
                );
            }

            $event = $eventDocument->data();

            if (!is_array($event)) {
                return $this->error(
                    'La informacion del evento no esta disponible.',
                    409
                );
            }

            // Consultar el tipo de entrada.
            $typeDocument = $firestore
                ->collection('events')
                ->document($eventId)
                ->collection('ticketTypes')
                ->document($ticketTypeId)
                ->snapshot();

            $type = $typeDocument->exists()
                ? $typeDocument->data()
                : null;

            $typeName = is_array($type)
                ? ($type['name'] ?? $ticketTypeId)
                : $ticketTypeId;

            // Recuperar la credencial QR protegida.
            $encrypted = $ticket['qrTokenEncrypted'] ?? null;

            if (
                !is_string($encrypted) ||
                $encrypted === ''
            ) {
                return $this->error(
                    'El codigo QR no esta disponible.',
                    409
                );
            }

            $qrValue = Crypt::decryptString($encrypted);

            // Generar el QR sin enviar datos a terceros.
            $qrImage = $qrService->dataUri($qrValue);

            $response = response()->view(
                'tickets.print',
                [
                    'ticketId' => $ticketId,
                    'orderId' => $orderId,
                    'eventName' => $event['name'] ?? 'Evento BluePass',
                    'eventVenue' => $event['venue'] ?? 'Por confirmar',
                    'eventDate' => $this->formatTimestamp(
                        $event['date'] ?? null
                    ),
                    'eventEndAt' => $this->formatTimestamp(
                        $event['endAt'] ?? null
                    ),
                    'eventTimezone' => $event['timezone'] ?? 'UTC',
                    'ticketTypeName' => $typeName,
                    'ticketPosition' => $ticket['position'] ?? null,
                    'ticketStatus' => 'valid',
                    'issuedAt' => $this->formatTimestamp(
                        $ticket['issuedAt'] ?? null
                    ),
                    'buyerName' => $order['buyer']['name'] ?? 'Comprador',
                    'qrImage' => $qrImage,
                ]
            );

            return $this->withoutCache($response);
        } catch (Throwable $exception) {
            Log::error('Error al preparar boleto imprimible', [
                'exception' => get_class($exception),
            ]);

            return $this->error(
                'No fue posible preparar el boleto imprimible.',
                500
            );
        }
    }

    /**
     * Validar identificadores de Firestore.
     */
    private function validId(string $value): bool
    {
        return preg_match(
            '/^[A-Za-z0-9_-]{1,128}$/D',
            $value
        ) === 1;
    }

    /**
     * Convertir fechas a formato ISO 8601.
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

    private function notFound(): Response
    {
        return $this->error(
            'El boleto no existe.',
            404
        );
    }

    /**
     * Responder con errores sin almacenar informacion.
     */
    private function error(
        string $message,
        int $status
    ): Response {
        return $this->withoutCache(
            response()->make(
                $message,
                $status,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            )
        );
    }

    /**
     * Evitar cache y exposicion de informacion privada.
     */
    private function withoutCache(Response $response): Response
    {
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

        $response->headers->set(
            'X-Frame-Options',
            'DENY'
        );

        $response->headers->set(
            'Referrer-Policy',
            'no-referrer'
        );

        return $response;
    }
}
