<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\UploadedFile;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class EventImageValidationTest extends TestCase
{
    /**
     * Exigir una imagen para subir la portada.
     */
    public function test_cover_requires_image(): void
    {
        $this->mockSellerEvent();

        $this->withToken('token-ficticio')
            ->postJson('/api/events/evento-prueba/images/cover', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    /**
     * Exigir una imagen para agregar a la galería.
     */
    public function test_gallery_requires_image(): void
    {
        $this->mockSellerEvent();

        $this->withToken('token-ficticio')
            ->postJson('/api/events/evento-prueba/images/gallery', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    /**
     * Rechazar archivos que no sean imágenes.
     */
    public function test_cover_rejects_pdf(): void
    {
        $this->mockSellerEvent();

        $file = UploadedFile::fake()->create(
            'documento.pdf',
            100,
            'application/pdf'
        );

        $this->withToken('token-ficticio')
            ->postJson('/api/events/evento-prueba/images/cover', [
                'image' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    /**
     * Rechazar archivos que superen cinco megabytes.
     */
    public function test_cover_rejects_oversized_file(): void
    {
        $this->mockSellerEvent();

        // El límite de Laravel se expresa en kilobytes.
        $file = UploadedFile::fake()->create(
            'imagen-grande.jpg',
            5121,
            'image/jpeg'
        );

        $this->withToken('token-ficticio')
            ->postJson('/api/events/evento-prueba/images/cover', [
                'image' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    /**
     * Impedir que se agregue una sexta imagen.
     */
    public function test_gallery_rejects_sixth_image(): void
    {
        $gallery = [];

        for ($i = 1; $i <= 5; $i++) {
            $gallery[] = [
                'id' => 'imagen-'.$i,
                'path' => 'events/evento-prueba/gallery/imagen-'.$i.'.jpg',
                'mimeType' => 'image/jpeg',
            ];
        }

        $this->mockSellerEvent($gallery);

        $this->withToken('token-ficticio')
            ->postJson('/api/events/evento-prueba/images/gallery', [])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'La galería ya tiene cinco imágenes.'
            );
    }

    /**
     * Simular un vendedor y su evento sin usar Firebase real.
     */
    private function mockSellerEvent(array $gallery = []): void
    {
        $uid = 'firebase-uid-prueba';

        // Simular la autenticación.
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

        // Simular el evento del vendedor.
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
                'imageUrl' => null,
                'galleryImages' => $gallery,
            ]);

        $eventDocument = Mockery::mock(
            DocumentReference::class
        );

        $eventDocument->shouldReceive('snapshot')
            ->once()
            ->andReturn($eventSnapshot);

        // Las validaciones no deben modificar el evento.
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

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }
}
