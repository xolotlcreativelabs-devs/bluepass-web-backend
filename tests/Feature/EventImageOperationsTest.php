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

class EventImageOperationsTest extends TestCase
{
    /**
     * Descargar la portada de un evento propio.
     */
    public function test_seller_can_download_own_cover(): void
    {
        $path = 'events/evento-prueba/cover/portada.png';
        $contents = 'contenido-de-imagen';

        $this->mockSellerEvent([
            'imagePath' => $path,
            'imageMimeType' => 'image/png',
        ]);

        $object = Mockery::mock(StorageObject::class);

        $object->shouldReceive('downloadAsString')
            ->once()
            ->andReturn($contents);

        $bucket = Mockery::mock(Bucket::class);

        $bucket->shouldReceive('object')
            ->once()
            ->with($path)
            ->andReturn($object);

        $this->mockStorage($bucket);

        $response = $this->withToken('token-ficticio')
            ->get('/api/events/evento-prueba/images/cover');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');

        // Comprobar las directivas sin depender de su orden.
        $cacheControl = $response->headers->get(
            'Cache-Control',
            ''
        );

        $this->assertStringContainsString(
            'private',
            $cacheControl
        );

        $this->assertStringContainsString(
            'no-store',
            $cacheControl
        );

        $this->assertSame(
            $contents,
            $response->getContent()
        );
    }

    /**
     * Reemplazar la portada mediante una transacción.
     */
    public function test_seller_can_replace_cover(): void
    {
        $oldPath = 'events/evento-prueba/cover/anterior.png';

        $this->mockSellerEvent(
            [
                'imagePath' => $oldPath,
                'imageMimeType' => 'image/png',
            ],
            'replace-cover'
        );

        $oldObject = Mockery::mock(StorageObject::class);

        $oldObject->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $oldObject->shouldReceive('delete')
            ->once();

        $bucket = Mockery::mock(Bucket::class);

        $bucket->shouldReceive('upload')
            ->once()
            ->with(
                Mockery::on(
                    fn ($stream) => is_resource($stream)
                ),
                Mockery::on(function ($options) use ($oldPath) {
                    $path = $options['name'] ?? '';

                    return is_string($path)
                        && str_starts_with(
                            $path,
                            'events/evento-prueba/cover/'
                        )
                        && str_ends_with($path, '.png')
                        && $path !== $oldPath
                        && (
                            $options['metadata']['contentType']
                            ?? null
                        ) === 'image/png';
                })
            )
            ->andReturn(Mockery::mock(StorageObject::class));

        // La portada anterior se elimina tras la transacción.
        $bucket->shouldReceive('object')
            ->once()
            ->with($oldPath)
            ->andReturn($oldObject);

        $this->mockStorage($bucket, 2);

        $this->withToken('token-ficticio')
            ->post(
                '/api/events/evento-prueba/images/cover',
                ['image' => $this->validImage()],
                ['Accept' => 'application/json']
            )
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Portada guardada correctamente.'
            )
            ->assertJsonPath('image.type', 'cover');
    }

    /**
     * Eliminar una imagen de galería mediante transacción.
     */
    public function test_seller_can_delete_gallery_image(): void
    {
        $path = 'events/evento-prueba/gallery/imagen-uno.png';

        $gallery = [
            [
                'id' => 'imagen-uno',
                'path' => $path,
                'mimeType' => 'image/png',
            ],
        ];

        $this->mockSellerEvent(
            ['galleryImages' => $gallery],
            'delete-gallery'
        );

        $object = Mockery::mock(StorageObject::class);

        $object->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $object->shouldReceive('delete')
            ->once();

        $bucket = Mockery::mock(Bucket::class);

        $bucket->shouldReceive('object')
            ->once()
            ->with($path)
            ->andReturn($object);

        $this->mockStorage($bucket);

        $this->withToken('token-ficticio')
            ->deleteJson(
                '/api/events/evento-prueba/images/imagen-uno'
            )
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Imagen eliminada del evento.'
            );
    }

    /**
     * Crear una imagen PNG válida para las pruebas.
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
            'portada.png',
            $contents
        );
    }

    /**
     * Simular autenticación y datos del evento.
     */
    private function mockSellerEvent(
        array $changes = [],
        string $action = 'none'
    ): void {
        $uid = 'firebase-uid-prueba';

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

        $event = array_merge([
            'sellerId' => $uid,
            'status' => 'draft',
            'imagePath' => null,
            'imageUrl' => null,
            'imageMimeType' => null,
            'galleryImages' => [],
        ], $changes);

        $eventSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $eventSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $eventSnapshot->shouldReceive('data')
            ->once()
            ->andReturn($event);

        $eventDocument = Mockery::mock(
            DocumentReference::class
        );

        $eventDocument->shouldReceive('snapshot')
            ->once()
            ->andReturn($eventSnapshot);

        // Las modificaciones se realizan mediante transacciones.
        $eventDocument->shouldNotReceive('update');

        $events = Mockery::mock(CollectionReference::class);

        $events->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($eventDocument);

        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('users')
            ->andReturn($users);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        if ($action === 'replace-cover') {
            $this->mockCoverReplacementTransaction(
                $firestore,
                $eventDocument,
                $event
            );
        } elseif ($action === 'delete-gallery') {
            $this->mockGalleryDeletionTransaction(
                $firestore,
                $eventDocument,
                $event
            );
        } else {
            $firestore->shouldNotReceive('runTransaction');
        }

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }

    /**
     * Simular la transacción para reemplazar una portada.
     */
    private function mockCoverReplacementTransaction(
        $firestore,
        $eventDocument,
        array $event
    ): void {
        $transactionSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $transactionSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $transactionSnapshot->shouldReceive('data')
            ->once()
            ->andReturn($event);

        $transaction = Mockery::mock(Transaction::class);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($eventDocument)
            ->andReturn($transactionSnapshot);

        $transaction->shouldReceive('update')
            ->once()
            ->with(
                $eventDocument,
                Mockery::on(function ($updates) use ($event) {
                    $fields = $this->fieldsFromUpdates($updates);

                    $newPath = $fields['imagePath'] ?? null;

                    return is_string($newPath)
                        && str_starts_with(
                            $newPath,
                            'events/evento-prueba/cover/'
                        )
                        && str_ends_with($newPath, '.png')
                        && $newPath !== $event['imagePath']
                        && ($fields['imageMimeType'] ?? null) === 'image/png'
                        && ($fields['imageUrl'] ?? null) ===
                            '/api/events/evento-prueba/images/cover'
                        && isset($fields['updatedAt']);
                })
            );

        $firestore->shouldReceive('runTransaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(
                function ($callback) use ($transaction) {
                    return $callback($transaction);
                }
            );
    }

    /**
     * Simular la transacción para eliminar una imagen.
     */
    private function mockGalleryDeletionTransaction(
        $firestore,
        $eventDocument,
        array $event
    ): void {
        $transactionSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $transactionSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $transactionSnapshot->shouldReceive('data')
            ->once()
            ->andReturn($event);

        $transaction = Mockery::mock(Transaction::class);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($eventDocument)
            ->andReturn($transactionSnapshot);

        $transaction->shouldReceive('update')
            ->once()
            ->with(
                $eventDocument,
                Mockery::on(function ($updates) {
                    $fields = $this->fieldsFromUpdates($updates);

                    return array_key_exists(
                        'galleryImages',
                        $fields
                    )
                        && $fields['galleryImages'] === []
                        && isset($fields['updatedAt']);
                })
            );

        $firestore->shouldReceive('runTransaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(
                function ($callback) use ($transaction) {
                    return $callback($transaction);
                }
            );
    }

    /**
     * Convertir actualizaciones de Firestore en campos.
     */
    private function fieldsFromUpdates(array $updates): array
    {
        $fields = [];

        foreach ($updates as $update) {
            if (
                !is_array($update) ||
                !isset($update['path']) ||
                !array_key_exists('value', $update)
            ) {
                return [];
            }

            $fields[$update['path']] = $update['value'];
        }

        return $fields;
    }

    /**
     * Simular Storage sin modificar Firebase real.
     */
    private function mockStorage(
        Bucket $bucket,
        int $calls = 1
    ): void {
        $storage = Mockery::mock(Storage::class);

        $storage->shouldReceive('getBucket')
            ->times($calls)
            ->andReturn($bucket);

        $this->app->instance(
            Storage::class,
            $storage
        );
    }
}
