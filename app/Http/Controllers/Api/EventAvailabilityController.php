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

                // Obtener las cantidades registradas.
                $capacity = $data['capacity'] ?? null;
                $sold = $data['sold'] ?? null;

                // Verificar que las cantidades sean validas.
                if (
                    !is_int($capacity) ||
                    !is_int($sold) ||
                    $capacity < 0 ||
                    $sold < 0
                ) {
                    throw new RuntimeException(
                        'Cantidades de boletos invalidas.'
                    );
                }

                // Evitar disponibilidades negativas.
                $available = max(0, $capacity - $sold);

                // Determinar si hay boletos disponibles.
                $isAvailable = $available > 0;

                $availability[] = [
                    'ticketTypeId' => $document->id(),
                    'name' => $data['name'] ?? null,
                    'capacity' => $capacity,
                    'sold' => $sold,
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
