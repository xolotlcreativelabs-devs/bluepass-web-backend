<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class EventAvailabilityController extends Controller
{
    /**
     * Consultar la disponibilidad de boletos de un evento.
     */
    public function index(
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {

        // Validar el identificador del evento.
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) {
            return response()->json([
                'message' => 'El evento no existe.',
            ], 404);
        }

        try {
            // Buscar el evento en Firestore.
            $eventDocument = $firestore
                ->collection('events')
                ->document($id);

            $eventSnapshot = $eventDocument->snapshot();

            // Verificar que exista.
            if (!$eventSnapshot->exists()) {
                return response()->json([
                    'message' => 'El evento no existe.',
                ], 404);
            }

            $event = $eventSnapshot->data();

            // Solo consultar disponibilidad de eventos publicados.
            if (
                !is_array($event) ||
                ($event['status'] ?? null) !== 'published'
            ) {
                return response()->json([
                    'message' => 'El evento no existe.',
                ], 404);
            }

            // Consultar los tipos de boletos activos.
            $documents = $eventDocument
                ->collection('ticketTypes')
                ->where('status', '=', 'active')
                ->limit(100)
                ->documents();

            $availability = [];

            foreach ($documents as $document) {

                if (!$document->exists()) {
                    continue;
                }

                $data = $document->data();

                if (
                    !is_array($data) ||
                    ($data['status'] ?? null) !== 'active'
                ) {
                    continue;
                }

                // Leer el inventario del tipo de boleto.
                $capacity = $data['capacity'] ?? null;
                $sold = $data['sold'] ?? null;

                // Los boletos anteriores no tienen reserved.
                $reserved = $data['reserved'] ?? 0;

                // Comprobar que los contadores sean validos.
                if (
                    !is_int($capacity) ||
                    !is_int($sold) ||
                    !is_int($reserved) ||
                    $capacity < 0 ||
                    $sold < 0 ||
                    $reserved < 0
                ) {
                    throw new RuntimeException(
                        'Los contadores del inventario son invalidos.'
                    );
                }

                // Detectar inventarios inconsistentes.
                if (
                    $sold > $capacity ||
                    $reserved > ($capacity - $sold)
                ) {
                    throw new RuntimeException(
                        'El inventario supera la capacidad del boleto.'
                    );
                }

                // Restar vendidos y reservados del inventario total.
                $available = $capacity - $sold - $reserved;

                $isAvailable = $available > 0;

                $availability[] = [
                    'ticketTypeId' => $document->id(),
                    'name' => $data['name'] ?? null,
                    'capacity' => $capacity,
                    'sold' => $sold,
                    'reserved' => $reserved,
                    'available' => $available,
                    'isAvailable' => $isAvailable,
                    'status' => $isAvailable
                        ? 'available'
                        : 'sold_out',
                ];
            }

            return response()->json([
                'message' => 'Disponibilidad consultada correctamente.',
                'eventId' => $id,
                'ticketTypes' => $availability,
            ], 200);

        } catch (Throwable $e) {

            Log::error(
                'Error al consultar disponibilidad BluePass',
                [
                    'exception' => get_class($e),
                ]
            );

            return response()->json([
                'message' => 'No fue posible consultar la disponibilidad.',
            ], 500);
        }
    }
}
