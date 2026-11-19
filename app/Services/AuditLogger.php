<?php

namespace App\Services;

use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditLogger
{
    /**
     * Preparar los datos de un registro de auditoría.
     */
    public function prepare(
        string $action,
        string $resourceType,
        string $resourceId,
        string $actorId,
        string $actorRole,
        array $changedFields = []
    ): ?array {
        // Solo aceptar recursos conocidos.
        if (!in_array($resourceType, ['events', 'news'], true)) {
            return null;
        }

        // Solo aceptar acciones permitidas.
        $allowedActions = [
            'created',
            'updated',
            'published',
            'unpublished',
        ];

        if (!in_array($action, $allowedActions, true)) {
            return null;
        }

        // Validar identificadores y permisos.
        if (
            trim($resourceId) === '' ||
            trim($actorId) === '' ||
            !in_array($actorRole, ['seller', 'admin'], true)
        ) {
            return null;
        }

        // Campos que pueden aparecer en el historial.
        $allowedFields = [
            'name',
            'title',
            'description',
            'content',
            'summary',
            'category',
            'venue',
            'timezone',
            'date',
            'endAt',
            'location',
            'startDate',
            'endDate',
            'status',
            'imageUrl',
            'images',
        ];

        // Registrar únicamente nombres de campos.
        $safeFields = array_values(
            array_unique(
                array_intersect(
                    $changedFields,
                    $allowedFields
                )
            )
        );

        return [
            'action' => $action,
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
            'actorId' => $actorId,
            'actorRole' => $actorRole,
            'changedFields' => $safeFields,
            'createdAt' => FieldValue::serverTimestamp(),
        ];
    }

    /**
     * Registrar una auditoría independiente.
     *
     * Este método no forma parte de una transacción
     * que actualice eventos o noticias.
     */
    public function record(
        FirestoreClient $firestore,
        string $action,
        string $resourceType,
        string $resourceId,
        string $actorId,
        string $actorRole,
        array $changedFields = []
    ): bool {
        $data = $this->prepare(
            $action,
            $resourceType,
            $resourceId,
            $actorId,
            $actorRole,
            $changedFields
        );

        if ($data === null) {
            return false;
        }

        try {
            $document = $firestore
                ->collection('audit_logs')
                ->newDocument();

            $document->set($data);

            return true;

        } catch (Throwable $e) {
            // No exponer información sensible.
            Log::error('No fue posible registrar la auditoría', [
                'action' => $action,
                'resourceType' => $resourceType,
                'exception' => get_class($e),
            ]);

            return false;
        }
    }
}
