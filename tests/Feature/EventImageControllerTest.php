<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class EventImageControllerTest extends TestCase
{
    /**
     * Exigir autenticación para consultar imágenes.
     */
    public function test_images_require_authentication(): void
    {
        $this->mockUnauthenticatedServices();

        $this->getJson('/api/events/evento-prueba/images')
            ->assertStatus(401);
    }

    /**
     * Exigir autenticación para subir portadas.
     */
    public function test_cover_upload_requires_authentication(): void
    {
        $this->mockUnauthenticatedServices();

        $this->postJson('/api/events/evento-prueba/images/cover', [])
            ->assertStatus(401);
    }

    /**
     * Exigir autenticación para subir imágenes de galería.
     */
    public function test_gallery_upload_requires_authentication(): void
    {
        $this->mockUnauthenticatedServices();

        $this->postJson('/api/events/evento-prueba/images/gallery', [])
            ->assertStatus(401);
    }

    /**
     * Exigir autenticación para descargar imágenes.
     */
    public function test_image_download_requires_authentication(): void
    {
        $this->mockUnauthenticatedServices();

        $this->getJson('/api/events/evento-prueba/images/cover')
            ->assertStatus(401);
    }

    /**
     * Exigir autenticación para eliminar imágenes.
     */
    public function test_image_deletion_requires_authentication(): void
    {
        $this->mockUnauthenticatedServices();

        $this->deleteJson('/api/events/evento-prueba/images/cover')
            ->assertStatus(401);
    }

    /**
     * Impedir que un comprador consulte imágenes privadas.
     */
    public function test_customer_cannot_manage_event_images(): void
    {
        $this->mockUser('customer');

        $this->withToken('token-ficticio')
            ->getJson('/api/events/evento-prueba/images')
            ->assertStatus(403);
    }

    /**
     * Impedir que un vendedor consulte imágenes ajenas.
     */
    public function test_seller_cannot_access_another_sellers_images(): void
    {
        $this->mockUser('seller', 'otro-vendedor');

        $this->withToken('token-ficticio')
            ->getJson('/api/events/evento-prueba/images')
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'No tienes permisos sobre este evento.'
            );
    }

    /**
     * Permitir consultar imágenes del evento propio.
     */
    public function test_seller_can_list_own_event_images(): void
    {
        $this->mockUser('seller', 'firebase-uid-prueba');

        $this->withToken('token-ficticio')
            ->getJson('/api/events/evento-prueba/images')
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Imágenes consultadas correctamente.'
            )
            ->assertJsonPath('images.cover', null)
            ->assertJsonPath('images.gallery', []);
    }

    /**
     * Permitir que un administrador consulte eventos ajenos.
     */
    public function test_admin_can_list_event_images(): void
    {
        $this->mockUser('admin', 'otro-vendedor');

        $this->withToken('token-ficticio')
            ->getJson('/api/events/evento-prueba/images')
            ->assertStatus(200)
            ->assertJsonPath('images.cover', null)
            ->assertJsonPath('images.gallery', []);
    }

    /**
     * Simular servicios cuando falta autenticación.
     */
    private function mockUnauthenticatedServices(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldNotReceive('verifyIdToken');

        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldNotReceive('collection');

        $this->app->instance(Auth::class, $auth);
        $this->app->instance(FirestoreClient::class, $firestore);
    }

    /**
     * Simular un usuario y un evento de Firestore.
     */
    private function mockUser(
        string $role,
        ?string $sellerId = null
    ): void {
        $uid = 'firebase-uid-prueba';

        // Simular el token de Firebase.
        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->once()
            ->andReturn(new DataSet([
                'sub' => $uid,
            ], ''));

        $user = UserRecord::fromResponseData([
            'localId' => $uid,
            'email' => 'usuario@ejemplo.com',
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

        // Simular el perfil del usuario.
        $profileSnapshot = Mockery::mock(
            DocumentSnapshot::class
        );

        $profileSnapshot->shouldReceive('exists')
            ->once()
            ->andReturn(true);

        $profileSnapshot->shouldReceive('data')
            ->once()
            ->andReturn([
                'name' => 'Usuario de prueba',
                'email' => 'usuario@ejemplo.com',
                'role' => $role,
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

        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('users')
            ->andReturn($users);

        if ($sellerId === null) {
            // Un comprador no debe acceder a la colección de eventos.
            $firestore->shouldNotReceive('collection')
                ->with('events');
        } else {
            // Simular el evento que se quiere consultar.
            $eventSnapshot = Mockery::mock(
                DocumentSnapshot::class
            );

            $eventSnapshot->shouldReceive('exists')
                ->once()
                ->andReturn(true);

            $eventSnapshot->shouldReceive('data')
                ->once()
                ->andReturn([
                    'sellerId' => $sellerId,
                    'status' => 'draft',
                    'imagePath' => null,
                    'imageUrl' => null,
                    'galleryImages' => [],
                ]);

            $eventDocument = Mockery::mock(
                DocumentReference::class
            );

            $eventDocument->shouldReceive('snapshot')
                ->once()
                ->andReturn($eventSnapshot);

            $events = Mockery::mock(
                CollectionReference::class
            );

            $events->shouldReceive('document')
                ->once()
                ->with('evento-prueba')
                ->andReturn($eventDocument);

            $firestore->shouldReceive('collection')
                ->once()
                ->with('events')
                ->andReturn($events);
        }

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );
    }
}
