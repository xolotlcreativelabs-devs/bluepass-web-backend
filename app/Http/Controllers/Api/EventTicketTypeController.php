<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventTicketTypeController extends Controller
{
    /**
     * Consultar los tipos de boletos de un evento publicado.
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
            // Consultar el evento.
            $eventDocument = $firestore
                ->collection('events')
                ->document($id);

            $eventSnapshot = $eventDocument->snapshot();

            // Verificar que el evento exista.
            if (!$eventSnapshot->exists()) {
                return response()->json([
                    'message' => 'El evento no existe.',
                ], 404);
            }

            $event = $eventSnapshot->data();

            // Solo permitir eventos publicados.
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

            $ticketTypes = [];

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

                // Preparar los campos publicos.
                $ticketTypes[] = [
                    'id' => $document->id(),
                    'name' => $data['name'] ?? null,
                    'description' => $data['description'] ?? null,
                    'price' => $data['price'] ?? null,
                    'currency' => 'MXN',
                    'capacity' => $data['capacity'] ?? null,
                    'sold' => $data['sold'] ?? null,
                    'status' => 'active',
                ];
            }

            return response()->json([
                'message' => 'Tipos de boletos consultados correctamente.',
                'eventId' => $id,
                'ticketTypes' => $ticketTypes,
            ], 200);

        } catch (Throwable $e) {

            Log::error(
                'Error al consultar tipos de boletos BluePass',
                [
                    'exception' => get_class($e),
                ]
            );

            return response()->json([
                'message' => 'No fue posible consultar los tipos de boletos.',
            ], 500);
        }
    }
}
