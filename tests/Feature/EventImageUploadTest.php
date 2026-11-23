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

class EventImageUploadTest extends TestCase
{
    /**
     * Guardar una portada válida mediante transacción.
     */
    public function test_seller_can_upload_cover(): void
    {
        $this->mockSellerAndEvent('cover');
        $this->mockStorageUpload('cover');

        $response = $this->withToken('token-ficticio')
            ->post(
                '/api/events/evento-prueba/images/cover',
                ['image' => $this->validImage()],
                ['Accept' => 'application/json']
            );

        $response
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Portada guardada correctamente.'
            )
            ->assertJsonPath('image.type', 'cover')
            ->assertJsonPath('image.mimeType', 'image/png')
            ->assertJsonPath(
                'image.url',
                '/api/events/evento-prueba/images/cover'
            );
    }

    /**
     * Agregar una imagen a la galería mediante transacción.
     */
    public function test_seller_can_upload_gallery_image(): void
    {
        $this->mockSellerAndEvent('gallery');
        $this->mockStorageUpload('gallery');

        $response = $this->withToken('token-ficticio')
            ->post(
                '/api/events/evento-prueba/images/gallery',
                ['image' => $this->validImage()],
                ['Accept' => 'application/json']
            );

        $response
            ->assertStatus(201)
            ->assertJsonPath(
                'message',
                'Imagen agregada a la galería.'
            )
            ->assertJsonPath('image.mimeType', 'image/png')
            ->assertJsonStructure([
                'image' => [
                    'id',
                    'mimeType',
                    'url',
                ],
            ]);

        $imageId = $response->json('image.id');

        $this->assertIsString($imageId);
        $this->assertNotEmpty($imageId);

        $this->assertSame(
            '/api/events/evento-prueba/images/'.$imageId,
            $response->json('image.url')
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
            'imagen.png',
            $contents
        );
    }

    /**
     * Simular al vendedor y su evento en Firestore.
     */
    private function mockSellerAndEvent(string $type): void
    {
        $uid = 'firebase-uid-prueba';

        // Simular la autenticación de Firebase.
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

        // Simular un evento sin imágenes.
        $event = [
            'sellerId' => $uid,
            'status' => 'draft',
            'imagePath' => null,
            'imageUrl' => null,
            'galleryImages' => [],
        ];

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

        // Ninguna subida debe actualizar el documento directamente.
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

        // Preparar la transacción según el tipo de imagen.
        $this->mockImageTransaction(
            $firestore,
            $eventDocument,
            $event,
            $type
        );

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }

    /**
     * Simular la transacción de portada o galería.
     */
    private function mockImageTransaction(
        $firestore,
        $eventDocument,
        array $event,
        string $type
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
                Mockery::on(function ($updates) use ($type) {
                    $fields = $this->fieldsFromUpdates($updates);

                    if (!isset($fields['updatedAt'])) {
                        return false;
                    }

                    if ($type === 'cover') {
                        return isset(
                            $fields['imagePath'],
                            $fields['imageMimeType'],
                            $fields['imageUrl']
                        )
                            && is_string($fields['imagePath'])
                            && str_starts_with(
                                $fields['imagePath'],
                                'events/evento-prueba/cover/'
                            )
                            && str_ends_with(
                                $fields['imagePath'],
                                '.png'
                            )
                            && $fields['imageMimeType'] === 'image/png'
                            && $fields['imageUrl'] ===
                                '/api/events/evento-prueba/images/cover';
                    }

                    $gallery = $fields['galleryImages'] ?? null;

                    if (!is_array($gallery) || count($gallery) !== 1) {
                        return false;
                    }

                    $image = $gallery[0];

                    return is_array($image)
                        && isset(
                            $image['id'],
                            $image['path'],
                            $image['mimeType']
                        )
                        && is_string($image['id'])
                        && $image['id'] !== ''
                        && $image['path'] ===
                            'events/evento-prueba/gallery/'.
                            $image['id'].'.png'
                        && $image['mimeType'] === 'image/png';
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
     * Convertir las actualizaciones de Firestore en campos.
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
     * Simular la subida a Firebase Storage.
     */
    private function mockStorageUpload(string $type): void
    {
        $folder = $type === 'cover'
            ? 'cover'
            : 'gallery';

        $bucket = Mockery::mock(Bucket::class);

        $bucket->shouldReceive('upload')
            ->once()
            ->with(
                Mockery::on(
                    fn ($stream) => is_resource($stream)
                ),
                Mockery::on(function ($options) use ($folder) {
                    return is_array($options)
                        && isset($options['name'])
                        && str_starts_with(
                            $options['name'],
                            'events/evento-prueba/'.$folder.'/'
                        )
                        && str_ends_with(
                            $options['name'],
                            '.png'
                        )
                        && (
                            $options['metadata']['contentType']
                            ?? null
                        ) === 'image/png';
                })
            )
            ->andReturn(
                Mockery::mock(StorageObject::class)
            );

        $storage = Mockery::mock(Storage::class);

        $storage->shouldReceive('getBucket')
            ->once()
            ->andReturn($bucket);

        $this->app->instance(
            Storage::class,
            $storage
        );
    }
}
