<?php

namespace Tests\Feature;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Storage;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventImagePermissionsTest extends TestCase
{
    /**
     * Impedir que un vendedor gestione imágenes ajenas.
     */
    #[DataProvider('imageRoutes')]
    public function test_seller_cannot_manage_another_sellers_images(
        string $method,
        string $path
    ): void {
        $this->mockUser('seller', 'otro-vendedor');

        $this->requestImageRoute($method, $path)
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'No tienes permisos sobre este evento.'
            );
    }

    /**
     * Impedir que un comprador gestione imágenes privadas.
     */
    #[DataProvider('imageRoutes')]
    public function test_customer_cannot_manage_event_images(
        string $method,
        string $path
    ): void {
        $this->mockUser('customer');

        $this->requestImageRoute($method, $path)
            ->assertStatus(403);
    }

    /**
     * Rutas que deben protegerse.
     */
    public static function imageRoutes(): array
    {
        return [
            'consultar galería' => [
                'GET',
                '/api/events/evento-prueba/images',
            ],
            'subir portada' => [
                'POST',
                '/api/events/evento-prueba/images/cover',
            ],
            'subir imagen de galería' => [
                'POST',
                '/api/events/evento-prueba/images/gallery',
            ],
            'descargar portada' => [
                'GET',
                '/api/events/evento-prueba/images/cover',
            ],
            'eliminar portada' => [
                'DELETE',
                '/api/events/evento-prueba/images/cover',
            ],
            'eliminar imagen de galería' => [
                'DELETE',
                '/api/events/evento-prueba/images/imagen-uno',
            ],
        ];
    }

    /**
     * Ejecutar una solicitud autenticada.
     */
    private function requestImageRoute(
        string $method,
        string $path
    ) {
        return $this->withToken('token-ficticio')
            ->json($method, $path);
    }

    /**
     * Simular el usuario y la propiedad de un evento.
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

        // Simular el perfil del usuario autenticado.
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

        $users = Mockery::mock(
            CollectionReference::class
        );

        $users->shouldReceive('document')
            ->once()
            ->with($uid)
            ->andReturn($userDocument);

        $firestore = Mockery::mock(
            FirestoreClient::class
        );

        $firestore->shouldReceive('collection')
            ->once()
            ->with('users')
            ->andReturn($users);

        if ($sellerId === null) {
            // Los compradores no deben consultar eventos privados.
            $firestore->shouldNotReceive('collection')
                ->with('events');
        } else {
            // El evento pertenece a otro vendedor.
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
                    'imagePath' =>
                        'events/evento-prueba/cover/portada.png',
                    'imageMimeType' => 'image/png',
                    'galleryImages' => [
                        [
                            'id' => 'imagen-uno',
                            'path' =>
                                'events/evento-prueba/gallery/imagen-uno.png',
                            'mimeType' => 'image/png',
                        ],
                    ],
                ]);

            $eventDocument = Mockery::mock(
                DocumentReference::class
            );

            $eventDocument->shouldReceive('snapshot')
                ->once()
                ->andReturn($eventSnapshot);

            // No permitir cambios directos en el documento.
            $eventDocument->shouldNotReceive('update');

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

            // No debe iniciarse una transacción sin autorización.
            $firestore->shouldNotReceive('runTransaction');
        }

        $this->app->instance(
            FirestoreClient::class,
            $firestore
        );

        // Ninguna solicitud rechazada debe usar Storage.
        $storage = Mockery::mock(Storage::class);

        $storage->shouldNotReceive('getBucket');

        $this->app->instance(
            Storage::class,
            $storage
        );
    }
}
