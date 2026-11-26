<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DeleteAccountController;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class DeleteAccountNestedReservationTest extends TestCase
{
    public function test_blocks_deletion_when_reservation_exists_inside_event(): void
    {
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->markTestSkipped(
                'Esta prueba requiere Firestore Emulator de BluePass.'
            );
        }

        $firestore = new FirestoreClient([
            'projectId' => 'demo-bluepass-hu04',
            'transport' => 'rest',
        ]);

        $uid = 'delete-account-test-' . bin2hex(random_bytes(10));
        $eventId = 'delete-account-event-' . bin2hex(random_bytes(10));

        $userDocument = $firestore
            ->collection('users')
            ->document($uid);

        $reservationDocument = $firestore
            ->collection('events')
            ->document($eventId)
            ->collection('reservations')
            ->document('test-reservation');

        try {
            $userDocument->set([
                'authSubject' => $uid,
                'name' => 'Usuario de prueba',
                'email' => $uid . '@example.test',
                'role' => 'customer',
                'status' => 'active',
            ]);

            $reservationDocument->set([
                'userId' => $uid,
                'eventId' => $eventId,
                'status' => 'active',
                'quantity' => 1,
            ]);

            $request = Request::create('/api/users/me', 'DELETE');

            $request->headers->set(
                'Authorization',
                'Bearer simulated-test-token'
            );

            $request->attributes->set('firebase_uid', $uid);

            $request->attributes->set('firebase_profile', [
                'role' => 'customer',
                'status' => 'active',
            ]);

            $token = Mockery::mock(UnencryptedToken::class);

            $token->shouldReceive('claims')
                ->andReturn(new DataSet([
                    'sub' => $uid,
                    'auth_time' => time(),
                ], ''));

            $auth = Mockery::mock(Auth::class);

            $auth->shouldReceive('verifyIdToken')
                ->once()
                ->with('simulated-test-token', true)
                ->andReturn($token);

            $auth->shouldNotReceive('deleteUser');

            $response = (new DeleteAccountController())->destroy(
                $request,
                $auth,
                $firestore
            );

            $this->assertSame(409, $response->getStatusCode());

            $data = json_decode($response->getContent(), true);

            $this->assertSame(
                'ACCOUNT_HAS_RELATED_RECORDS',
                $data['code'] ?? null
            );

            $this->assertTrue($userDocument->snapshot()->exists());
            $this->assertTrue($reservationDocument->snapshot()->exists());

            $this->assertSame(
                'active',
                $userDocument->snapshot()->data()['status'] ?? null
            );
        } finally {
            $reservationDocument->delete();
            $userDocument->delete();
        }
    }
}
