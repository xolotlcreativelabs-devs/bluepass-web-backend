<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageObject;
use Illuminate\Http\UploadedFile;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Storage;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class EventImageConcurrencyTest extends TestCase
{
    /**
     * Rechazar una subida si la galería se llena
     * antes de completar la transacción.
     */
    public function test_gallery_rejects_concurrent_sixth_image(): void
    {
        $uid = 'firebase-uid-prueba';
        $eventId = 'evento-prueba';

        // Inicialmente hay cuatro imágenes.
        $initialGallery = $this->galleryWithCount(4);

        // Al ejecutar la transacción ya hay cinco.
        $updatedGallery = $this->galleryWithCount(5);

        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->once()
            ->andReturn(new DataSet([
                'sub' => $uid,
            ], ''));

        $user = UserRecord::fromResponseData([
            'localId' => $uid,
            'email' => 'vendedor@ejemplo.com',
            'emailVerified' => true,
            'disabled' => false,
            'createdAt' => '2026-11-12T12:00:00Z',
        ]);

        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-ficticio', true)
            ->andReturn($token);

        $auth->shouldReceive('getUser')
            ->once()
            ->with($uid)
            ->andReturn($user);

        $this->app->instance(Auth::class, $auth);

        // Simular el perfil del vendedor.
        $profileSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $profileSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $profileSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'name' => 'Vendedor de prueba',
                'email' => 'vendedor@ejemplo.com',
                'role' => 'seller',
                'status' => 'active',
                'authSubject' => $uid,
            ]);

        $userDocument = Mockery::mock(
            DocumentReference::class
        );

        $userDocument->shouldReceive('snapshot')
            ->once()
            ->andReturn($profileSnapshot);

        $users = Mockery::mock(CollectionReference::class);

        $users->shouldReceive('document')
            ->once()
            ->with($uid)
            ->andReturn($userDocument);

        // Primera lectura: cuatro imágenes.
        $eventSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $eventSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $eventSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'sellerId' => $uid,
                'status' => 'draft',
                'galleryImages' => $initialGallery,
            ]);

        $eventDocument = Mockery::mock(
            DocumentReference::class
        );

        $eventDocument->shouldReceive('snapshot')
            ->once()
            ->andReturn($eventSnapshot);

        // No debe modificar el evento directamente.
        $eventDocument->shouldNotReceive('update');

        $events = Mockery::mock(CollectionReference::class);

        $events->shouldReceive('document')
            ->once()
            ->with($eventId)
            ->andReturn($eventDocument);

        // Segunda lectura: la galería ya se llenó.
        $transactionSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $transactionSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $transactionSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'sellerId' => $uid,
                'status' => 'draft',
                'galleryImages' => $updatedGallery,
            ]);

        $transaction = Mockery::mock(Transaction::class);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($eventDocument)
            ->andReturn($transactionSnapshot);

        // La transacción no debe agregar la sexta imagen.
        $transaction->shouldNotReceive('update');

        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('users')
            ->andReturn($users);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        $firestore->shouldReceive('runTransaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(
                function ($callback) use ($transaction) {
                    return $callback($transaction);
                }
            );

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );

        // Guardar la ruta del archivo que se intenta subir.
        $uploadedPath = null;

        $bucket = Mockery::mock(Bucket::class);

        $bucket->shouldReceive('upload')
            ->once()
            ->with(
                Mockery::on(
                    fn ($stream) => is_resource($stream)
                ),
                Mockery::on(
                    function ($options) use (&$uploadedPath) {
                        $uploadedPath = $options['name'] ?? null;

                        return is_string($uploadedPath)
                            && str_starts_with(
                                $uploadedPath,
                                'events/evento-prueba/gallery/'
                            )
                            && str_ends_with($uploadedPath, '.png')
                            && (
                                $options['metadata']['contentType']
                                ?? null
                            ) === 'image/png';
                    }
                )
            )
            ->andReturn(Mockery::mock(StorageObject::class));

        // Verificar que se limpie el archivo rechazado.
        $uploadedObject = Mockery::mock(StorageObject::class);

        $uploadedObject->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $uploadedObject->shouldReceive('delete')
            ->once();

        $bucket->shouldReceive('object')
            ->once()
            ->with(Mockery::on(
                function ($path) use (&$uploadedPath) {
                    return $uploadedPath !== null
                        && $path === $uploadedPath;
                }
            ))
            ->andReturn($uploadedObject);

        $storage = Mockery::mock(Storage::class);

        $storage->shouldReceive('getBucket')
            ->twice()
            ->andReturn($bucket);

        $this->app->instance(Storage::class, $storage);

        // Ejecutar la solicitud de subida.
        $response = $this->withToken('token-ficticio')
            ->post(
                '/api/events/evento-prueba/images/gallery',
                ['image' => $this->validImage()],
                ['Accept' => 'application/json']
            );

        $response
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'La galería ya tiene cinco imágenes.'
            );
    }

    /**
     * Crear una galería simulada.
     */
    private function galleryWithCount(int $count): array
    {
        $gallery = [];

        for ($i = 1; $i <= $count; $i++) {
            $gallery[] = [
                'id' => 'imagen-'.$i,
                'path' =>
                    'events/evento-prueba/gallery/imagen-'.$i.'.png',
                'mimeType' => 'image/png',
            ];
        }

        return $gallery;
    }

    /**
     * Crear una imagen PNG válida.
     */
    private function validImage(): UploadedFile
    {
        $contents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91Jpz'.
            'AAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAA'.
            'AABJRU5ErkJggg==',
            true
        );

        return UploadedFile::fake()->createWithContent(
            'imagen.png',
            $contents
        );
    }
}
