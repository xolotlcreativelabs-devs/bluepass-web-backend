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

class EventImageCoverConcurrencyTest extends TestCase
{
    /**
     * Reemplazar la portada sin borrar una referencia equivocada.
     */
    public function test_cover_replacement_uses_latest_event_version(): void
    {
        $uid = 'firebase-uid-prueba';

        $firstPath = 'events/evento-prueba/cover/primera.png';
        $currentPath = 'events/evento-prueba/cover/segunda.png';

        // Autenticación simulada del vendedor.
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

        // Perfil del vendedor.
        $profileSnapshot = Mockery::mock(DocumentSnapshot::class);

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

        $userDocument = Mockery::mock(DocumentReference::class);

        $userDocument->shouldReceive('snapshot')
            ->once()
            ->andReturn($profileSnapshot);

        $users = Mockery::mock(CollectionReference::class);

        $users->shouldReceive('document')
            ->once()
            ->with($uid)
            ->andReturn($userDocument);

        // Primera lectura: la portada es primera.png.
        $eventSnapshot = Mockery::mock(DocumentSnapshot::class);

        $eventSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $eventSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'sellerId' => $uid,
                'status' => 'draft',
                'imagePath' => $firstPath,
                'imageMimeType' => 'image/png',
            ]);

        $eventDocument = Mockery::mock(DocumentReference::class);

        $eventDocument->shouldReceive('snapshot')
            ->once()
            ->andReturn($eventSnapshot);

        // La actualización debe hacerse mediante una transacción.
        $eventDocument->shouldNotReceive('update');

        $events = Mockery::mock(CollectionReference::class);

        $events->shouldReceive('document')
            ->once()
            ->with('evento-prueba')
            ->andReturn($eventDocument);

        // Segunda lectura: otra solicitud cambió la portada.
        $transactionSnapshot = Mockery::mock(DocumentSnapshot::class);

        $transactionSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $transactionSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'sellerId' => $uid,
                'status' => 'draft',
                'imagePath' => $currentPath,
                'imageMimeType' => 'image/png',
            ]);

        $transaction = Mockery::mock(Transaction::class);

        $transaction->shouldReceive('snapshot')
            ->once()
            ->with($eventDocument)
            ->andReturn($transactionSnapshot);

        // La transacción guarda una portada nueva.
        $transaction->shouldReceive('update')
            ->once()
            ->with(
                $eventDocument,
                Mockery::on(function ($updates) use ($currentPath) {
                    if (!is_array($updates)) {
                        return false;
                    }

                    $fields = [];

                    foreach ($updates as $update) {
                        if (
                            !is_array($update) ||
                            !isset($update['path']) ||
                            !array_key_exists('value', $update)
                        ) {
                            return false;
                        }

                        $fields[$update['path']] = $update['value'];
                    }

                    $newPath = $fields['imagePath'] ?? null;

                    return is_string($newPath)
                        && str_starts_with(
                            $newPath,
                            'events/evento-prueba/cover/'
                        )
                        && $newPath !== $currentPath
                        && str_ends_with($newPath, '.png')
                        && ($fields['imageMimeType'] ?? null) === 'image/png'
                        && isset($fields['updatedAt']);
                })
            );

        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('users')
            ->andReturn($users);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('events')
            ->andReturn($events);

        // Ejecutar la transacción simulada.
        $firestore->shouldReceive('runTransaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(function ($callback) use ($transaction) {
                return $callback($transaction);
            });

        $this->app->instance(FirestoreClient::class, $firestore);

        // Simular la subida de una nueva portada.
        $bucket = Mockery::mock(Bucket::class);

        $bucket->shouldReceive('upload')
            ->once()
            ->with(
                Mockery::on(
                    fn ($stream) => is_resource($stream)
                ),
                Mockery::on(function ($options) {
                    $path = $options['name'] ?? null;

                    return is_string($path)
                        && str_starts_with(
                            $path,
                            'events/evento-prueba/cover/'
                        )
                        && str_ends_with($path, '.png')
                        && (
                            $options['metadata']['contentType']
                            ?? null
                        ) === 'image/png';
                })
            )
            ->andReturn(Mockery::mock(StorageObject::class));

        // Solo se debe limpiar la portada vigente anterior.
        $previousObject = Mockery::mock(StorageObject::class);

        $previousObject->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $previousObject->shouldReceive('delete')
            ->once();

        $bucket->shouldReceive('object')
            ->once()
            ->with($currentPath)
            ->andReturn($previousObject);

        $storage = Mockery::mock(Storage::class);

        $storage->shouldReceive('getBucket')
            ->twice()
            ->andReturn($bucket);

        $this->app->instance(Storage::class, $storage);

        // Solicitar el reemplazo.
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
            );
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
            'portada.png',
            $contents
        );
    }
}
