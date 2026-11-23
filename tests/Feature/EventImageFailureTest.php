<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageObject;
use Illuminate\Http\UploadedFile;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Storage;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EventImageFailureTest extends TestCase
{
    /**
     * Responder correctamente cuando falla Firebase Storage.
     */
    public function test_cover_handles_storage_failure(): void
    {
        $this->mockSellerAndEvent();

        $storage = Mockery::mock(Storage::class);

        // Simular un error al acceder a Storage.
        $storage->shouldReceive('getBucket')
            ->once()
            ->andThrow(
                new RuntimeException(
                    'Storage no disponible.'
                )
            );

        $this->app->instance(Storage::class, $storage);

        $this->withToken('token-ficticio')
            ->post(
                '/api/events/evento-prueba/images/cover',
                ['image' => $this->validImage()],
                ['Accept' => 'application/json']
            )
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible guardar la portada.'
            );
    }

    /**
     * Limpiar la nueva imagen cuando falla la transacción.
     */
    public function test_cover_cleans_file_when_firestore_fails(): void
    {
        $this->mockSellerAndEvent(true);

        // Registrar la ruta del archivo subido.
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
                                'events/evento-prueba/cover/'
                            )
                            && str_ends_with(
                                $uploadedPath,
                                '.png'
                            )
                            && (
                                $options['metadata']['contentType']
                                ?? null
                            ) === 'image/png';
                    }
                )
            )
            ->andReturn(
                Mockery::mock(StorageObject::class)
            );

        // El archivo recién subido debe eliminarse.
        $uploadedObject = Mockery::mock(
            StorageObject::class
        );

        $uploadedObject->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $uploadedObject->shouldReceive('delete')
            ->once();

        $bucket->shouldReceive('object')
            ->once()
            ->with(
                Mockery::on(
                    function ($path) use (&$uploadedPath) {
                        return $uploadedPath !== null
                            && $path === $uploadedPath;
                    }
                )
            )
            ->andReturn($uploadedObject);

        $storage = Mockery::mock(Storage::class);

        // Una consulta para subir y otra para limpiar.
        $storage->shouldReceive('getBucket')
            ->twice()
            ->andReturn($bucket);

        $this->app->instance(Storage::class, $storage);

        $this->withToken('token-ficticio')
            ->post(
                '/api/events/evento-prueba/images/cover',
                ['image' => $this->validImage()],
                ['Accept' => 'application/json']
            )
            ->assertStatus(500)
            ->assertJsonPath(
                'message',
                'No fue posible guardar la portada.'
            );

        $this->assertNotNull($uploadedPath);
    }

    /**
     * Simular autenticación, vendedor y evento.
     */
    private function mockSellerAndEvent(
        bool $firestoreFails = false
    ): void {
        $uid = 'firebase-uid-prueba';

        // Simular el token de Firebase.
        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->once()
            ->andReturn(
                new DataSet([
                    'sub' => $uid,
                ], '')
            );

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

        $users = Mockery::mock(
            CollectionReference::class
        );

        $users->shouldReceive('document')
            ->once()
            ->with($uid)
            ->andReturn($userDocument);

        // Simular un evento perteneciente al vendedor.
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
                'imagePath' => null,
                'imageMimeType' => null,
                'imageUrl' => null,
                'galleryImages' => [],
            ]);

        $eventDocument = Mockery::mock(
            DocumentReference::class
        );

        $eventDocument->shouldReceive('snapshot')
            ->once()
            ->andReturn($eventSnapshot);

        // No debe actualizar directamente el evento.
        $eventDocument->shouldNotReceive('update');

        $events = Mockery::mock(
            CollectionReference::class
        );

        $events->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($eventDocument);

        $firestore = Mockery::mock(
            FirestoreClient::class
        );

        $firestore->shouldReceive('collection')
            ->once()
            ->with('users')
            ->andReturn($users);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        if ($firestoreFails) {
            // El archivo se sube, pero falla la transacción.
            $firestore->shouldReceive('runTransaction')
                ->once()
                ->with(Mockery::type('callable'))
                ->andThrow(
                    new RuntimeException(
                        'Error de escritura en Firestore.'
                    )
                );
        } else {
            // Si falla Storage, nunca se inicia la transacción.
            $firestore->shouldNotReceive(
                'runTransaction'
            );
        }

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }

    /**
     * Crear un archivo PNG válido para las pruebas.
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
}
