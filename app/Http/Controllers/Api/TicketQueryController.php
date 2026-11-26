<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeInterface;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class TicketQueryController extends Controller
{
    private const PAGE_LIMIT = 50;

    /**
     * Consultar los boletos del comprador autenticado.
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
                ->collection('tickets')
                ->where('userId', '=', $uid)
                ->limit(self::PAGE_LIMIT)
                ->documents();

            $tickets = [];

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

                $tickets[] = $this->formatTicket(
                    $document->id(),
                    $data
                );
            }

            return response()->json([
                'message' => 'Boletos consultados correctamente.',
                'tickets' => $tickets,
                'count' => count($tickets),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al consultar boletos BluePass', [
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar los boletos.',
            ], 500);
        }
    }

    /**
     * Consultar el detalle de un boleto propio.
     */
    public function show(
        Request $request,
        string $ticketId,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $this->customerId($request);

        if ($uid === null) {
            return $this->unauthorized($request);
        }

        if (!$this->validId($ticketId)) {
            return $this->notFound();
        }

        try {
            $document = $firestore
                ->collection('tickets')
                ->document($ticketId)
                ->snapshot();

            if (!$document->exists()) {
                return $this->notFound();
            }

            $data = $document->data();

            if (
                !is_array($data) ||
                ($data['userId'] ?? null) !== $uid
            ) {
                return $this->notFound();
            }

            return response()->json([
                'message' => 'Boleto consultado correctamente.',
                'ticket' => $this->formatTicket(
                    $ticketId,
                    $data
                ),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al consultar detalle de boleto', [
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar el boleto.',
            ], 500);
        }
    }

    /**
     * Obtener la credencial QR de un boleto propio.
     */
    public function qr(
        Request $request,
        string $ticketId,
        FirestoreClient $firestore
    ): JsonResponse {
        $uid = $this->customerId($request);

        if ($uid === null) {
            return $this->withoutCache(
                $this->unauthorized($request)
            );
        }

        if (!$this->validId($ticketId)) {
            return $this->withoutCache(
                $this->notFound()
            );
        }

        try {
            $document = $firestore
                ->collection('tickets')
                ->document($ticketId)
                ->snapshot();

            if (!$document->exists()) {
                return $this->withoutCache(
                    $this->notFound()
                );
            }

            $ticket = $document->data();

            if (
                !is_array($ticket) ||
                ($ticket['userId'] ?? null) !== $uid
            ) {
                return $this->withoutCache(
                    $this->notFound()
                );
            }

            if (($ticket['status'] ?? null) !== 'valid') {
                return $this->withoutCache(
                    response()->json([
                        'message' => 'El QR no esta disponible para este boleto.',
                    ], 409)
                );
            }

            $encrypted = $ticket['qrTokenEncrypted'] ?? null;

            if (!is_string($encrypted) || $encrypted === '') {
                Log::warning('Boleto sin credencial QR', [
                    'ticketId' => $ticketId,
                ]);

                return $this->withoutCache(
                    response()->json([
                        'message' => 'El QR del boleto no esta disponible.',
                    ], 409)
                );
            }

            $token = Crypt::decryptString($encrypted);

            return $this->withoutCache(
                response()->json([
                    'message' => 'Credencial QR consultada correctamente.',
                    'ticketId' => $ticketId,
                    'qrValue' => $token,
                    'status' => 'valid',
                ])
            );
        } catch (Throwable $exception) {
            Log::error('Error al consultar QR de boleto', [
                'exception' => get_class($exception),
            ]);

            return $this->withoutCache(
                response()->json([
                    'message' => 'No fue posible consultar el QR del boleto.',
                ], 500)
            );
        }
    }

    /**
     * Obtener el identificador del comprador.
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
     * Responder cuando el usuario no esta autorizado.
     */
    private function unauthorized(Request $request): JsonResponse
    {
        $uid = $request->attributes->get('firebase_uid');

        $status = is_string($uid) && $uid !== ''
            ? 403
            : 401;

        return response()->json([
            'message' => 'No tienes permisos para consultar boletos.',
        ], $status);
    }

    /**
     * Responder sin revelar informacion de otros compradores.
     */
    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'El boleto no existe.',
        ], 404);
    }

    /**
     * Preparar informacion publica para el comprador.
     */
    private function formatTicket(
        string $ticketId,
        array $ticket
    ): array {
        return [
            'id' => $ticketId,
            'orderId' => $ticket['orderId'] ?? null,
            'eventId' => $ticket['eventId'] ?? null,
            'ticketTypeId' => $ticket['ticketTypeId'] ?? null,
            'position' => $ticket['position'] ?? null,
            'status' => $ticket['status'] ?? null,
            'issuedAt' => $this->formatTimestamp(
                $ticket['issuedAt'] ?? null
            ),
            'usedAt' => $this->formatTimestamp(
                $ticket['usedAt'] ?? null
            ),
        ];
    }

    /**
     * Convertir una fecha de Firestore a formato ISO 8601.
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
     * Validar identificadores de documentos.
     */
    private function validId(string $value): bool
    {
        return preg_match(
            '/^[A-Za-z0-9_-]{1,128}$/D',
            $value
        ) === 1;
    }

    /**
     * Evitar almacenamiento en cache de credenciales QR.
     */
    private function withoutCache(
        JsonResponse $response
    ): JsonResponse {
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
