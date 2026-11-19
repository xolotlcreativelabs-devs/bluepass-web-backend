<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Storage;
use InvalidArgumentException;
use OverflowException;
use RuntimeException;
use Throwable;

class EventImageController extends Controller
{
    // Restricciones provisionales para las imágenes.
    private const MAX_GALLERY_IMAGES = 5;

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Consultar las imágenes de un evento.
     */
    public function index(
        Request $request,
        string $id,
        FirestoreClient $firestore
    ): JsonResponse {
        $result = $this->authorizedEvent(
            $request,
            $id,
            $firestore
        );

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $event = $result['event'];

        return response()->json([
            'message' => 'Imágenes consultadas correctamente.',
            'images' => [
                'cover' => $this->coverResponse($id, $event),
                'gallery' => $this->galleryResponse($id, $event),
            ],
        ]);
    }

    /**
     * Subir o reemplazar la portada con una transacción.
     */
    public function uploadCover(
        Request $request,
        string $id,
        FirestoreClient $firestore,
        Storage $storage
    ): JsonResponse {
        $result = $this->authorizedEvent(
            $request,
            $id,
            $firestore
        );

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $validated = $this->validateImage($request);

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $file = $validated['image'];
        $mimeType = $file->getMimeType();

        $path = 'events/'.$id.'/cover/'.
            Str::uuid()->toString().'.'.
            $this->extensionForMime($mimeType);

        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');
        $document = $result['document'];

        $uploaded = false;
        $previousPath = null;

        try {
            // Subir el archivo antes de iniciar la transacción.
            $bucket = $storage->getBucket();

            $this->uploadToBucket(
                $bucket,
                $file,
                $path,
                $mimeType
            );

            $uploaded = true;

            // Consultar y actualizar la portada vigente.
            $previousPath = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $document,
                    $uid,
                    $role,
                    $path,
                    $mimeType,
                    $id
                ): ?string {
                    $snapshot = $transaction->snapshot(
                        $document
                    );

                    if (!$snapshot->exists()) {
                        throw new RuntimeException(
                            'El evento dejó de existir.'
                        );
                    }

                    $event = $snapshot->data();

                    if (!is_array($event)) {
                        throw new RuntimeException(
                            'No fue posible leer el evento.'
                        );
                    }

                    if (
                        $role === 'seller' &&
                        ($event['sellerId'] ?? null) !== $uid
                    ) {
                        throw new AuthorizationException(
                            'No tienes permisos sobre este evento.'
                        );
                    }

                    // Esta es la portada más reciente.
                    $oldPath = $event['imagePath'] ?? null;

                    $transaction->update($document, [
                        [
                            'path' => 'imagePath',
                            'value' => $path,
                        ],
                        [
                            'path' => 'imageMimeType',
                            'value' => $mimeType,
                        ],
                        [
                            'path' => 'imageUrl',
                            'value' => $this->imageUrl($id, 'cover'),
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => FieldValue::serverTimestamp(),
                        ],
                    ]);

                    return is_string($oldPath)
                        ? $oldPath
                        : null;
                }
            );
        } catch (AuthorizationException $e) {
            if ($uploaded) {
                $this->deleteQuietly($storage, $path);
            }

            return response()->json([
                'message' => 'No tienes permisos sobre este evento.',
            ], 403);
        } catch (Throwable $e) {
            if ($uploaded) {
                $this->deleteQuietly($storage, $path);
            }

            Log::error('No fue posible guardar la portada', [
                'eventId' => $id,
                'errorType' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible guardar la portada.',
            ], 500);
        }

        // Limpiar la portada que estaba vigente al confirmar
        // el cambio en Firestore.
        if (
            is_string($previousPath) &&
            $this->isEventPath($id, $previousPath) &&
            $previousPath !== $path
        ) {
            $this->deleteQuietly(
                $storage,
                $previousPath
            );
        }

        return response()->json([
            'message' => 'Portada guardada correctamente.',
            'image' => [
                'type' => 'cover',
                'mimeType' => $mimeType,
                'url' => $this->imageUrl($id, 'cover'),
            ],
        ], 200);
    }

    /**
     * Agregar una imagen a la galería mediante transacción.
     */
    public function uploadGallery(
        Request $request,
        string $id,
        FirestoreClient $firestore,
        Storage $storage
    ): JsonResponse {
        $result = $this->authorizedEvent(
            $request,
            $id,
            $firestore
        );

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $currentGallery = $result['event']['galleryImages'] ?? [];

        if (!is_array($currentGallery)) {
            return response()->json([
                'message' => 'La galería del evento no es válida.',
            ], 500);
        }

        if (count($currentGallery) >= self::MAX_GALLERY_IMAGES) {
            return response()->json([
                'message' => 'La galería ya tiene cinco imágenes.',
            ], 422);
        }

        $validated = $this->validateImage($request);

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $file = $validated['image'];
        $mimeType = $file->getMimeType();
        $imageId = Str::uuid()->toString();

        $path = 'events/'.$id.'/gallery/'.
            $imageId.'.'.$this->extensionForMime($mimeType);

        $image = [
            'id' => $imageId,
            'path' => $path,
            'mimeType' => $mimeType,
        ];

        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');
        $document = $result['document'];

        $uploaded = false;

        try {
            $bucket = $storage->getBucket();

            $this->uploadToBucket(
                $bucket,
                $file,
                $path,
                $mimeType
            );

            $uploaded = true;

            $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $document,
                    $image,
                    $uid,
                    $role
                ): void {
                    $snapshot = $transaction->snapshot(
                        $document
                    );

                    if (!$snapshot->exists()) {
                        throw new RuntimeException(
                            'El evento dejó de existir.'
                        );
                    }

                    $event = $snapshot->data();

                    if (!is_array($event)) {
                        throw new RuntimeException(
                            'No fue posible leer el evento.'
                        );
                    }

                    if (
                        $role === 'seller' &&
                        ($event['sellerId'] ?? null) !== $uid
                    ) {
                        throw new AuthorizationException(
                            'No tienes permisos sobre este evento.'
                        );
                    }

                    $gallery = $event['galleryImages'] ?? [];

                    if (!is_array($gallery)) {
                        throw new RuntimeException(
                            'La galería no es válida.'
                        );
                    }

                    if (count($gallery) >= self::MAX_GALLERY_IMAGES) {
                        throw new OverflowException(
                            'La galería ya tiene cinco imágenes.'
                        );
                    }

                    $gallery[] = $image;

                    $transaction->update($document, [
                        [
                            'path' => 'galleryImages',
                            'value' => $gallery,
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => FieldValue::serverTimestamp(),
                        ],
                    ]);
                }
            );
        } catch (OverflowException $e) {
            if ($uploaded) {
                $this->deleteQuietly($storage, $path);
            }

            return response()->json([
                'message' => 'La galería ya tiene cinco imágenes.',
            ], 422);
        } catch (AuthorizationException $e) {
            if ($uploaded) {
                $this->deleteQuietly($storage, $path);
            }

            return response()->json([
                'message' => 'No tienes permisos sobre este evento.',
            ], 403);
        } catch (Throwable $e) {
            if ($uploaded) {
                $this->deleteQuietly($storage, $path);
            }

            Log::error('No fue posible guardar imagen de galería', [
                'eventId' => $id,
                'errorType' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible guardar la imagen.',
            ], 500);
        }

        return response()->json([
            'message' => 'Imagen agregada a la galería.',
            'image' => [
                'id' => $imageId,
                'mimeType' => $mimeType,
                'url' => $this->imageUrl($id, $imageId),
            ],
        ], 201);
    }

    /**
     * Descargar una imagen autorizada.
     */
    public function show(
        Request $request,
        string $id,
        string $imageId,
        FirestoreClient $firestore,
        Storage $storage
    ): Response|JsonResponse {
        $result = $this->authorizedEvent(
            $request,
            $id,
            $firestore
        );

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $image = $this->findImage(
            $result['event'],
            $imageId
        );

        if ($image === null) {
            return response()->json([
                'message' => 'La imagen no existe.',
            ], 404);
        }

        if (!$this->isEventPath($id, $image['path'])) {
            return response()->json([
                'message' => 'La referencia de imagen no es válida.',
            ], 422);
        }

        try {
            $contents = $storage
                ->getBucket()
                ->object($image['path'])
                ->downloadAsString();

            return response($contents, 200, [
                'Content-Type' => $image['mimeType'],
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $e) {
            Log::error('No fue posible consultar imagen', [
                'eventId' => $id,
                'errorType' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar la imagen.',
            ], 500);
        }
    }

    /**
     * Eliminar una imagen mediante transacción.
     */
    public function destroy(
        Request $request,
        string $id,
        string $imageId,
        FirestoreClient $firestore,
        Storage $storage
    ): JsonResponse {
        $result = $this->authorizedEvent(
            $request,
            $id,
            $firestore
        );

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');
        $document = $result['document'];

        try {
            $image = $firestore->runTransaction(
                function (Transaction $transaction) use (
                    $document,
                    $imageId,
                    $id,
                    $uid,
                    $role
                ): ?array {
                    $snapshot = $transaction->snapshot(
                        $document
                    );

                    if (!$snapshot->exists()) {
                        return null;
                    }

                    $event = $snapshot->data();

                    if (!is_array($event)) {
                        throw new RuntimeException(
                            'No fue posible leer el evento.'
                        );
                    }

                    if (
                        $role === 'seller' &&
                        ($event['sellerId'] ?? null) !== $uid
                    ) {
                        throw new AuthorizationException(
                            'No tienes permisos sobre este evento.'
                        );
                    }

                    $image = $this->findImage(
                        $event,
                        $imageId
                    );

                    if ($image === null) {
                        return null;
                    }

                    if (
                        !is_string($image['path']) ||
                        !$this->isEventPath($id, $image['path'])
                    ) {
                        throw new InvalidArgumentException(
                            'La referencia de imagen no es válida.'
                        );
                    }

                    if ($imageId === 'cover') {
                        $updates = [
                            [
                                'path' => 'imagePath',
                                'value' => null,
                            ],
                            [
                                'path' => 'imageMimeType',
                                'value' => null,
                            ],
                            [
                                'path' => 'imageUrl',
                                'value' => null,
                            ],
                        ];
                    } else {
                        $gallery = $event['galleryImages'] ?? [];

                        if (!is_array($gallery)) {
                            throw new RuntimeException(
                                'La galería no es válida.'
                            );
                        }

                        $gallery = array_values(
                            array_filter(
                                $gallery,
                                fn ($item) =>
                                    is_array($item) &&
                                    ($item['id'] ?? null) !== $imageId
                            )
                        );

                        $updates = [
                            [
                                'path' => 'galleryImages',
                                'value' => $gallery,
                            ],
                        ];
                    }

                    $updates[] = [
                        'path' => 'updatedAt',
                        'value' => FieldValue::serverTimestamp(),
                    ];

                    $transaction->update(
                        $document,
                        $updates
                    );

                    return $image;
                }
            );
        } catch (AuthorizationException $e) {
            return response()->json([
                'message' => 'No tienes permisos sobre este evento.',
            ], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => 'La referencia de imagen no es válida.',
            ], 422);
        } catch (Throwable $e) {
            Log::error('No fue posible eliminar imagen del evento', [
                'eventId' => $id,
                'errorType' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible eliminar la imagen.',
            ], 500);
        }

        if ($image === null) {
            return response()->json([
                'message' => 'La imagen no existe.',
            ], 404);
        }

        $this->deleteQuietly(
            $storage,
            $image['path']
        );

        return response()->json([
            'message' => 'Imagen eliminada del evento.',
        ], 200);
    }

    /**
     * Verificar identidad, rol y propiedad del evento.
     */
    private function authorizedEvent(
        Request $request,
        string $id,
        FirestoreClient $firestore
    ): array|JsonResponse {
        $uid = $request->attributes->get('firebase_uid');
        $role = $request->attributes->get('firebase_role');

        if (!is_string($uid) || $uid === '') {
            return response()->json([
                'message' => 'No fue posible verificar la identidad.',
            ], 401);
        }

        if (!in_array($role, ['seller', 'admin'], true)) {
            return response()->json([
                'message' => 'No tienes permisos para gestionar imágenes.',
            ], 403);
        }

        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) {
            return response()->json([
                'message' => 'El identificador del evento no es válido.',
            ], 422);
        }

        try {
            $document = $firestore
                ->collection('events')
                ->document($id);

            $snapshot = $document->snapshot();

            if (!$snapshot->exists()) {
                return response()->json([
                    'message' => 'El evento no existe.',
                ], 404);
            }

            $event = $snapshot->data();

            if (!is_array($event)) {
                return response()->json([
                    'message' => 'No fue posible consultar el evento.',
                ], 500);
            }

            if (
                $role === 'seller' &&
                ($event['sellerId'] ?? null) !== $uid
            ) {
                return response()->json([
                    'message' => 'No tienes permisos sobre este evento.',
                ], 403);
            }

            return [
                'document' => $document,
                'event' => $event,
            ];
        } catch (Throwable $e) {
            Log::error('No fue posible consultar evento para imágenes', [
                'eventId' => $id,
                'errorType' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible consultar el evento.',
            ], 500);
        }
    }

    /**
     * Validar tamaño y tipo de imagen.
     */
    private function validateImage(
        Request $request
    ): array|JsonResponse {
        $validator = Validator::make($request->all(), [
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'La imagen enviada no es válida.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('image');

        if (
            $file === null ||
            !in_array(
                $file->getMimeType(),
                self::ALLOWED_MIME_TYPES,
                true
            )
        ) {
            return response()->json([
                'message' => 'El formato de imagen no está permitido.',
            ], 422);
        }

        return ['image' => $file];
    }

    /**
     * Subir una imagen privada a Storage.
     */
    private function uploadToBucket(
        $bucket,
        $file,
        string $path,
        string $mimeType
    ): void {
        $stream = fopen(
            $file->getRealPath(),
            'rb'
        );

        if ($stream === false) {
            throw new RuntimeException(
                'No fue posible abrir el archivo.'
            );
        }

        try {
            $bucket->upload($stream, [
                'name' => $path,
                'metadata' => [
                    'contentType' => $mimeType,
                ],
            ]);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Buscar una imagen registrada en Firestore.
     */
    private function findImage(
        array $event,
        string $imageId
    ): ?array {
        if ($imageId === 'cover') {
            $path = $event['imagePath'] ?? null;

            if (!is_string($path) || $path === '') {
                return null;
            }

            return [
                'path' => $path,
                'mimeType' => $event['imageMimeType']
                    ?? 'application/octet-stream',
            ];
        }

        foreach ($event['galleryImages'] ?? [] as $image) {
            if (
                is_array($image) &&
                ($image['id'] ?? null) === $imageId &&
                isset(
                    $image['path'],
                    $image['mimeType']
                )
            ) {
                return $image;
            }
        }

        return null;
    }

    /**
     * Preparar la portada para la respuesta.
     */
    private function coverResponse(
        string $id,
        array $event
    ): ?array {
        if (empty($event['imagePath'])) {
            return null;
        }

        return [
            'type' => 'cover',
            'mimeType' => $event['imageMimeType'] ?? null,
            'url' => $this->imageUrl($id, 'cover'),
        ];
    }

    /**
     * Preparar la galería para la respuesta.
     */
    private function galleryResponse(
        string $id,
        array $event
    ): array {
        $images = [];

        foreach ($event['galleryImages'] ?? [] as $image) {
            if (
                !is_array($image) ||
                !isset(
                    $image['id'],
                    $image['mimeType']
                )
            ) {
                continue;
            }

            $images[] = [
                'id' => $image['id'],
                'mimeType' => $image['mimeType'],
                'url' => $this->imageUrl(
                    $id,
                    $image['id']
                ),
            ];
        }

        return $images;
    }

    /**
     * Construir la ruta de descarga.
     */
    private function imageUrl(
        string $eventId,
        string $imageId
    ): string {
        return '/api/events/'.
            $eventId.'/images/'.$imageId;
    }

    /**
     * Comprobar la carpeta del evento.
     */
    private function isEventPath(
        string $eventId,
        string $path
    ): bool {
        return str_starts_with(
            $path,
            'events/'.$eventId.'/'
        );
    }

    /**
     * Limpiar un archivo sin interrumpir la respuesta.
     */
    private function deleteQuietly(
        Storage $storage,
        string $path
    ): void {
        try {
            $object = $storage
                ->getBucket()
                ->object($path);

            if ($object->exists()) {
                $object->delete();
            }
        } catch (Throwable $e) {
            Log::warning('No fue posible limpiar archivo de Storage', [
                'errorType' => get_class($e),
            ]);
        }
    }

    /**
     * Obtener la extensión a partir del MIME.
     */
    private function extensionForMime(
        string $mimeType
    ): string {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new InvalidArgumentException(
                'Formato de imagen no permitido.'
            ),
        };
    }
}
