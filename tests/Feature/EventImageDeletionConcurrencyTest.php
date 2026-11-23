<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageObject;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Storage;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class EventImageDeletionConcurrencyTest extends TestCase
{
    /**
     * Conservar una imagen agregada mientras se elimina otra.
     */
    public function test_deletion_preserves_concurrently_added_image(): void
    {
        $uid = 'firebase-uid-prueba';
        $eventId = 'evento-prueba';

        // Primera lectura: existen dos imágenes.
        $initialGallery = [
            $this->galleryImage('imagen-uno'),
            $this->galleryImage('imagen-dos'),
        ];

        // Segunda lectura: otra solicitud agregó una tercera.
        $updatedGallery = [
            $this->galleryImage('imagen-uno'),
            $this->galleryImage('imagen-dos'),
            $this->galleryImage('imagen-tres'),
        ];

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

        // Preparar la primera lectura del evento.
        $eventSnapshot = Mockery::mock(DocumentSnapshot::class);

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

        $eventDocument = Mockery::mock(DocumentReference::class);

        $eventDocument->shouldReceive('snapshot')
            ->once()
            ->andReturn($eventSnapshot);

        // La eliminación no debe escribir datos desactualizados.
        $eventDocument->shouldNotReceive('update');

        $events = Mockery::mock(CollectionReference::class);

        $events->shouldReceive('document')
            ->once()
            ->with($eventId)
            ->andReturn($eventDocument);

        // La transacción debe leer la galería más reciente.
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

        // Debe eliminar imagen-uno, conservando las otras dos.
        $transaction->shouldReceive('update')
            ->once()
            ->with(
                $eventDocument,
                Mockery::on(function ($updates) {
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

                    $gallery = $fields['galleryImages'] ?? null;

                    return is_array($gallery)
                        && count($gallery) === 2
                        && ($gallery[0]['id'] ?? null) === 'imagen-dos'
                        && ($gallery[1]['id'] ?? null) === 'imagen-tres'
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

        // Simular la eliminación del archivo correcto.
        $path = 'events/evento-prueba/gallery/imagen-uno.png';

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

        $storage = Mockery::mock(Storage::class);

        $storage->shouldReceive('getBucket')
            ->once()
            ->andReturn($bucket);

        $this->app->instance(Storage::class, $storage);

        // Solicitar la eliminación de la primera imagen.
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
     * Preparar una imagen registrada en la galería.
     */
    private function galleryImage(string $id): array
    {
        return [
            'id' => $id,
            'path' =>
                'events/evento-prueba/gallery/'.$id.'.png',
            'mimeType' => 'image/png',
        ];
    }
}
