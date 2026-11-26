<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Crypt;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class TicketHttpAuthorizationTest extends TestCase
{
    private FirestoreClient $firestore;

    private DocumentReference $ownUserDocument;

    private DocumentReference $otherUserDocument;

    private DocumentReference $ownTicketDocument;

    private DocumentReference $otherTicketDocument;

    private DocumentReference $usedTicketDocument;

    private string $ownUserId;

    private string $otherUserId;

    private string $ownTicketId;

    private string $otherTicketId;

    private string $usedTicketId;

    private string $qrToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Utilizar solamente Firestore Emulator.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->fail(
                'Estas pruebas requieren Firestore Emulator.'
            );
        }

        $this->firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        // El middleware y el controlador usan el mismo emulador.
        $this->app->instance(
            FirestoreClient::class,
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->ownUserId = 'cliente-ticket-http-' . $suffix;
        $this->otherUserId = 'otro-ticket-http-' . $suffix;

        $this->ownTicketId = 'ticket-http-own-' . $suffix;
        $this->otherTicketId = 'ticket-http-other-' . $suffix;
        $this->usedTicketId = 'ticket-http-used-' . $suffix;

        $this->qrToken = bin2hex(random_bytes(32));

        $users = $this->firestore->collection('users');
        $tickets = $this->firestore->collection('tickets');

        $this->ownUserDocument = $users
            ->document($this->ownUserId);

        $this->otherUserDocument = $users
            ->document($this->otherUserId);

        $this->ownTicketDocument = $tickets
            ->document($this->ownTicketId);

        $this->otherTicketDocument = $tickets
            ->document($this->otherTicketId);

        $this->usedTicketDocument = $tickets
            ->document($this->usedTicketId);

        $this->ownUserDocument->set(
            $this->userData($this->ownUserId)
        );

        $this->otherUserDocument->set(
            $this->userData($this->otherUserId)
        );

        $this->ownTicketDocument->set(
            $this->ticketData(
                $this->ownUserId,
                'valid',
                $this->qrToken
            )
        );

        $this->otherTicketDocument->set(
            $this->ticketData(
                $this->otherUserId,
                'valid',
                bin2hex(random_bytes(32))
            )
        );

        $this->usedTicketDocument->set(
            $this->ticketData(
                $this->ownUserId,
                'used',
                bin2hex(random_bytes(32))
            )
        );
    }

    /**
     * Preparar un perfil de comprador.
     */
    private function userData(
        string $uid,
        string $role = 'customer'
    ): array {
        return [
            'name' => 'Comprador de prueba',
            'email' => 'cliente@ejemplo.com',
            'role' => $role,
            'status' => 'active',
            'authSubject' => $uid,
        ];
    }

    /**
     * Preparar un boleto de prueba.
     */
    private function ticketData(
        string $userId,
        string $status,
        string $token
    ): array {
        $now = new Timestamp(new DateTimeImmutable());

        return [
            'orderId' => 'pedido-http-prueba',
            'eventId' => 'evento-http-prueba',
            'ticketTypeId' => 'general',
            'userId' => $userId,
            'position' => 1,
            'status' => $status,
            'qrTokenHash' => hash('sha256', $token),
            'qrTokenEncrypted' => Crypt::encryptString($token),
            'issuedAt' => $now,
            'usedAt' => $status === 'used' ? $now : null,
            'updatedAt' => $now,
        ];
    }

    /**
     * Simular un token valido de Firebase.
     */
    private function authenticate(
        string $uid,
        string $role = 'customer'
    ): void {
        $userDocument = $uid === $this->otherUserId
            ? $this->otherUserDocument
            : $this->ownUserDocument;

        $userDocument->update([
            [
                'path' => 'role',
                'value' => $role,
            ],
        ]);

        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->once()
            ->andReturn(new DataSet([
                'sub' => $uid,
            ], ''));

        $firebaseUser = UserRecord::fromResponseData([
            'localId' => $uid,
            'email' => 'cliente@ejemplo.com',
            'emailVerified' => true,
            'disabled' => false,
            'createdAt' => '2026-11-12T12:00:00Z',
        ]);

        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-prueba', true)
            ->andReturn($token);

        $auth->shouldReceive('getUser')
            ->once()
            ->with($uid)
            ->andReturn($firebaseUser);

        $this->app->instance(Auth::class, $auth);
    }

    /**
     * Los boletos requieren iniciar sesion.
     */
    public function test_list_requires_authentication(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldNotReceive('verifyIdToken');

        $this->app->instance(Auth::class, $auth);

        $this->getJson('/api/tickets')
            ->assertStatus(401);
    }

    /**
     * El detalle de un boleto requiere iniciar sesion.
     */
    public function test_detail_requires_authentication(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldNotReceive('verifyIdToken');

        $this->app->instance(Auth::class, $auth);

        $this->getJson(
            '/api/tickets/' . $this->ownTicketId
        )->assertStatus(401);
    }

    /**
     * El QR requiere iniciar sesion.
     */
    public function test_qr_requires_authentication(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldNotReceive('verifyIdToken');

        $this->app->instance(Auth::class, $auth);

        $this->getJson(
            '/api/tickets/' . $this->ownTicketId . '/qr'
        )->assertStatus(401);
    }

    /**
     * Rechazar tokens invalidos.
     */
    public function test_list_rejects_invalid_token(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-invalido', true)
            ->andThrow(
                new FailedToVerifyToken('Token invalido.')
            );

        $auth->shouldNotReceive('getUser');

        $this->app->instance(Auth::class, $auth);

        $this->withToken('token-invalido')
            ->getJson('/api/tickets')
            ->assertStatus(401);
    }

    /**
     * Los vendedores no pueden consultar boletos de compradores.
     */
    public function test_seller_cannot_list_tickets(): void
    {
        $this->authenticate(
            $this->ownUserId,
            'seller'
        );

        $this->withToken('token-prueba')
            ->getJson('/api/tickets')
            ->assertStatus(403);
    }

    /**
     * Los vendedores no pueden consultar QR de compradores.
     */
    public function test_seller_cannot_get_qr(): void
    {
        $this->authenticate(
            $this->ownUserId,
            'seller'
        );

        $this->withToken('token-prueba')
            ->getJson(
                '/api/tickets/' . $this->ownTicketId . '/qr'
            )
            ->assertStatus(403);
    }

    /**
     * El comprador solo recibe sus boletos.
     */
    public function test_customer_lists_only_own_tickets(): void
    {
        $this->authenticate($this->ownUserId);

        $response = $this->withToken('token-prueba')
            ->getJson('/api/tickets');

        $response->assertStatus(200);

        $ids = array_column(
            $response->json('tickets'),
            'id'
        );

        $this->assertContains($this->ownTicketId, $ids);
        $this->assertContains($this->usedTicketId, $ids);
        $this->assertNotContains($this->otherTicketId, $ids);

        $response->assertJsonMissingPath(
            'tickets.0.qrTokenEncrypted'
        );

        $response->assertJsonMissingPath(
            'tickets.0.qrTokenHash'
        );

        $response->assertJsonMissingPath(
            'tickets.0.qrValue'
        );
    }

    /**
     * El comprador puede consultar el detalle de su boleto.
     */
    public function test_customer_can_view_own_ticket(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->getJson(
                '/api/tickets/' . $this->ownTicketId
            )
            ->assertStatus(200)
            ->assertJsonPath(
                'ticket.id',
                $this->ownTicketId
            )
            ->assertJsonPath(
                'ticket.status',
                'valid'
            )
            ->assertJsonMissingPath(
                'ticket.qrTokenEncrypted'
            )
            ->assertJsonMissingPath(
                'ticket.qrTokenHash'
            );
    }

    /**
     * No permitir acceso al boleto de otra persona.
     */
    public function test_customer_cannot_view_another_ticket(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->getJson(
                '/api/tickets/' . $this->otherTicketId
            )
            ->assertStatus(404)
            ->assertJsonMissingPath('ticket');
    }

    /**
     * El QR propio se devuelve sin permitir cache.
     */
    public function test_customer_can_get_own_qr(): void
    {
        $this->authenticate($this->ownUserId);

        $response = $this->withToken('token-prueba')
            ->getJson(
                '/api/tickets/' . $this->ownTicketId . '/qr'
            );

        $response->assertStatus(200)
            ->assertJsonPath(
                'ticketId',
                $this->ownTicketId
            )
            ->assertJsonPath(
                'qrValue',
                $this->qrToken
            )
            ->assertJsonPath(
                'status',
                'valid'
            );

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get(
                'Cache-Control',
                ''
            )
        );
    }

    /**
     * No permitir acceder al QR de otra persona.
     */
    public function test_customer_cannot_get_another_qr(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->getJson(
                '/api/tickets/' . $this->otherTicketId . '/qr'
            )
            ->assertStatus(404)
            ->assertJsonMissingPath('qrValue');
    }

    /**
     * No permitir obtener QR de boletos utilizados.
     */
    public function test_used_ticket_cannot_get_qr(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->getJson(
                '/api/tickets/' . $this->usedTicketId . '/qr'
            )
            ->assertStatus(409)
            ->assertJsonMissingPath('qrValue');
    }

    /**
     * Un comprador no puede indicar otro usuario en la consulta.
     */
    public function test_list_rejects_user_id_parameter(): void
    {
        $this->authenticate($this->ownUserId);

        $this->withToken('token-prueba')
            ->getJson(
                '/api/tickets?userId=' . $this->otherUserId
            )
            ->assertStatus(422);
    }

    /**
     * El segundo comprador no puede ver el boleto del primero.
     */
    public function test_other_customer_cannot_view_own_users_ticket(): void
    {
        $this->authenticate($this->otherUserId);

        $this->withToken('token-prueba')
            ->getJson(
                '/api/tickets/' . $this->ownTicketId
            )
            ->assertStatus(404)
            ->assertJsonMissingPath('ticket');
    }

    /**
     * El segundo comprador no puede obtener el QR del primero.
     */
    public function test_other_customer_cannot_get_own_users_qr(): void
    {
        $this->authenticate($this->otherUserId);

        $this->withToken('token-prueba')
            ->getJson(
                '/api/tickets/' . $this->ownTicketId . '/qr'
            )
            ->assertStatus(404)
            ->assertJsonMissingPath('qrValue');
    }

    /**
     * Eliminar los datos de prueba.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->ownTicketDocument)) {
                $this->ownTicketDocument->delete();
            }

            if (isset($this->otherTicketDocument)) {
                $this->otherTicketDocument->delete();
            }

            if (isset($this->usedTicketDocument)) {
                $this->usedTicketDocument->delete();
            }

            if (isset($this->ownUserDocument)) {
                $this->ownUserDocument->delete();
            }

            if (isset($this->otherUserDocument)) {
                $this->otherUserDocument->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
