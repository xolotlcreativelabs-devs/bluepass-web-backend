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

class TicketPrintHttpAuthorizationTest extends TestCase
{
    private FirestoreClient $firestore;

    private string $buyerId;
    private string $otherBuyerId;
    private string $eventId;
    private string $orderId;
    private string $pendingOrderId;

    private array $ticketIds = [];

    private DocumentReference $buyerDocument;
    private DocumentReference $otherBuyerDocument;

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutar solamente con Firestore Emulator.
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

        // Compartir el emulador con el middleware y controlador.
        $this->app->instance(
            FirestoreClient::class,
            $this->firestore
        );

        $suffix = bin2hex(random_bytes(8));

        $this->buyerId = 'cliente-print-http-' . $suffix;
        $this->otherBuyerId = 'otro-print-http-' . $suffix;
        $this->eventId = 'evento-print-http-' . $suffix;
        $this->orderId = 'pedido-print-http-' . $suffix;
        $this->pendingOrderId = 'pendiente-print-http-' . $suffix;

        $users = $this->firestore->collection('users');

        $this->buyerDocument = $users
            ->document($this->buyerId);

        $this->otherBuyerDocument = $users
            ->document($this->otherBuyerId);

        $this->buyerDocument->set(
            $this->userData($this->buyerId)
        );

        $this->otherBuyerDocument->set(
            $this->userData($this->otherBuyerId)
        );

        $now = new DateTimeImmutable();
        $timestamp = new Timestamp($now);

        $event = $this->firestore
            ->collection('events')
            ->document($this->eventId);

        $event->set([
            'name' => 'Concierto de prueba BluePass',
            'venue' => 'Auditorio BluePass',
            'timezone' => 'America/Mexico_City',
            'date' => new Timestamp(
                $now->modify('+5 days')
            ),
            'endAt' => new Timestamp(
                $now->modify('+5 days +3 hours')
            ),
            'status' => 'published',
        ]);

        $event
            ->collection('ticketTypes')
            ->document('general')
            ->set([
                'name' => 'Entrada General',
                'status' => 'active',
            ]);

        $this->firestore
            ->collection('orders')
            ->document($this->orderId)
            ->set([
                'userId' => $this->buyerId,
                'eventId' => $this->eventId,
                'status' => 'paid',
                'paidAt' => $timestamp,
                'buyer' => [
                    'name' => 'Comprador BluePass',
                    'email' => 'cliente@ejemplo.com',
                ],
            ]);

        $this->firestore
            ->collection('orders')
            ->document($this->pendingOrderId)
            ->set([
                'userId' => $this->buyerId,
                'eventId' => $this->eventId,
                'status' => 'pending',
                'paidAt' => null,
            ]);

        $this->createTicket(
            'valid',
            $suffix,
            $this->buyerId,
            $this->orderId,
            'valid'
        );

        $this->createTicket(
            'other',
            $suffix,
            $this->otherBuyerId,
            $this->orderId,
            'valid'
        );

        $this->createTicket(
            'used',
            $suffix,
            $this->buyerId,
            $this->orderId,
            'used'
        );

        $this->createTicket(
            'pending',
            $suffix,
            $this->buyerId,
            $this->pendingOrderId,
            'valid'
        );
    }

    /**
     * Preparar el perfil del usuario de prueba.
     */
    private function userData(
        string $uid,
        string $role = 'customer'
    ): array {
        return [
            'name' => 'Comprador BluePass',
            'email' => 'cliente@ejemplo.com',
            'role' => $role,
            'status' => 'active',
            'authSubject' => $uid,
        ];
    }

    /**
     * Crear un boleto individual en el emulador.
     */
    private function createTicket(
        string $name,
        string $suffix,
        string $userId,
        string $orderId,
        string $status
    ): void {
        $ticketId = 'ticket-print-http-' . $name . '-' . $suffix;

        $this->ticketIds[$name] = $ticketId;

        $token = bin2hex(random_bytes(32));
        $now = new Timestamp(new DateTimeImmutable());

        $this->firestore
            ->collection('tickets')
            ->document($ticketId)
            ->set([
                'orderId' => $orderId,
                'eventId' => $this->eventId,
                'ticketTypeId' => 'general',
                'userId' => $userId,
                'position' => 1,
                'status' => $status,
                'qrTokenHash' => hash('sha256', $token),
                'qrTokenEncrypted' => Crypt::encryptString(
                    $token
                ),
                'issuedAt' => $now,
                'usedAt' => $status === 'used' ? $now : null,
            ]);
    }

    /**
     * Simular un token Firebase correctamente verificado.
     */
    private function authenticate(
        string $uid,
        string $role = 'customer'
    ): void {
        $document = $uid === $this->otherBuyerId
            ? $this->otherBuyerDocument
            : $this->buyerDocument;

        $document->update([
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
     * Obtener la ruta de impresion.
     */
    private function printPath(string $name): string
    {
        return '/api/tickets/' .
            $this->ticketIds[$name] .
            '/print';
    }

    public function test_print_requires_authentication(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldNotReceive('verifyIdToken');

        $this->app->instance(Auth::class, $auth);

        $this->get($this->printPath('valid'))
            ->assertStatus(401);
    }

    public function test_print_rejects_invalid_token(): void
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
            ->get($this->printPath('valid'))
            ->assertStatus(401);
    }

    public function test_seller_cannot_print_customer_ticket(): void
    {
        $this->authenticate(
            $this->buyerId,
            'seller'
        );

        $this->withToken('token-prueba')
            ->get($this->printPath('valid'))
            ->assertStatus(403);
    }

    public function test_customer_can_print_own_paid_ticket(): void
    {
        $this->authenticate($this->buyerId);

        $response = $this->withToken('token-prueba')
            ->get($this->printPath('valid'));

        $response->assertStatus(200);

        $response->assertSee(
            'Concierto de prueba BluePass'
        );

        $response->assertSee(
            'Entrada General'
        );

        $response->assertSee(
            'data:image/svg+xml;base64,',
            false
        );

        $response->assertSee(
            $this->ticketIds['valid']
        );

        $this->assertStringContainsString(
            'text/html',
            $response->headers->get('Content-Type', '')
        );
    }

    public function test_customer_cannot_print_another_ticket(): void
    {
        $this->authenticate($this->buyerId);

        $this->withToken('token-prueba')
            ->get($this->printPath('other'))
            ->assertStatus(404);
    }

    public function test_other_customer_cannot_print_first_ticket(): void
    {
        $this->authenticate($this->otherBuyerId);

        $this->withToken('token-prueba')
            ->get($this->printPath('valid'))
            ->assertStatus(404);
    }

    public function test_used_ticket_cannot_be_printed(): void
    {
        $this->authenticate($this->buyerId);

        $this->withToken('token-prueba')
            ->get($this->printPath('used'))
            ->assertStatus(409);
    }

    public function test_pending_purchase_cannot_print_ticket(): void
    {
        $this->authenticate($this->buyerId);

        $this->withToken('token-prueba')
            ->get($this->printPath('pending'))
            ->assertStatus(409);
    }

    public function test_print_rejects_unexpected_parameters(): void
    {
        $this->authenticate($this->buyerId);

        $this->withToken('token-prueba')
            ->get(
                $this->printPath('valid') .
                '?userId=' . $this->otherBuyerId
            )
            ->assertStatus(422);
    }

    public function test_printed_ticket_disables_cache(): void
    {
        $this->authenticate($this->buyerId);

        $response = $this->withToken('token-prueba')
            ->get($this->printPath('valid'));

        $response->assertStatus(200);

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', '')
        );

        $this->assertSame(
            'nosniff',
            $response->headers->get(
                'X-Content-Type-Options'
            )
        );

        $this->assertSame(
            'DENY',
            $response->headers->get('X-Frame-Options')
        );

        $this->assertSame(
            'no-referrer',
            $response->headers->get('Referrer-Policy')
        );
    }

    /**
     * Eliminar los documentos temporales.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->firestore)) {
                foreach ($this->ticketIds as $ticketId) {
                    $this->firestore
                        ->collection('tickets')
                        ->document($ticketId)
                        ->delete();
                }

                if (isset($this->orderId)) {
                    $this->firestore
                        ->collection('orders')
                        ->document($this->orderId)
                        ->delete();
                }

                if (isset($this->pendingOrderId)) {
                    $this->firestore
                        ->collection('orders')
                        ->document($this->pendingOrderId)
                        ->delete();
                }

                if (isset($this->eventId)) {
                    $event = $this->firestore
                        ->collection('events')
                        ->document($this->eventId);

                    $event
                        ->collection('ticketTypes')
                        ->document('general')
                        ->delete();

                    $event->delete();
                }

                if (isset($this->buyerDocument)) {
                    $this->buyerDocument->delete();
                }

                if (isset($this->otherBuyerDocument)) {
                    $this->otherBuyerDocument->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
