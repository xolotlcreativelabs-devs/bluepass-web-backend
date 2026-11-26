<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DeleteAccountController;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DeleteAccountControllerTest extends TestCase
{
    public function test_rejects_requests_without_verified_identity(): void
    {
        $request = Request::create('/api/users/me', 'DELETE');

        $this->assertRejectedWithoutFirestore($request, 401);
    }

    public function test_rejects_non_customer_accounts(): void
    {
        $request = $this->customerRequest('test-admin', 'admin');

        $this->assertRejectedWithoutFirestore($request, 403);
    }

    public function test_requires_authentication_token(): void
    {
        $request = $this->customerRequest();

        $this->assertRejectedWithoutFirestore($request, 401);
    }

    public function test_rejects_invalid_authentication_token(): void
    {
        $request = $this->customerRequest();
        $request->headers->set(
            'Authorization',
            'Bearer invalid-test-token'
        );

        $auth = Mockery::mock(Auth::class);
        $firestore = Mockery::mock(FirestoreClient::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('invalid-test-token', true)
            ->andThrow(new RuntimeException('Invalid test token'));

        $auth->shouldNotReceive('deleteUser');
        $firestore->shouldNotReceive('collection');

        $response = (new DeleteAccountController())->destroy(
            $request,
            $auth,
            $firestore
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_rejects_unexpected_request_fields(): void
    {
        $request = $this->customerRequest();

        $request->request->add([
            'uid' => 'another-customer',
        ]);

        $this->assertRejectedWithoutFirestore($request, 422);
    }

    public function test_rejects_token_belonging_to_another_user(): void
    {
        $request = $this->customerRequest();
        $request->headers->set(
            'Authorization',
            'Bearer test-token'
        );

        $auth = $this->authWithClaims([
            'sub' => 'another-customer',
            'auth_time' => time(),
        ]);

        $response = $this->executeWithNoFirestore(
            $request,
            $auth
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_rejects_old_authentication(): void
    {
        $request = $this->customerRequest();
        $request->headers->set(
            'Authorization',
            'Bearer test-token'
        );

        $auth = $this->authWithClaims([
            'sub' => 'test-customer',
            'auth_time' => time() - 600,
        ]);

        $response = $this->executeWithNoFirestore(
            $request,
            $auth
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_rejects_token_without_authentication_time(): void
    {
        $request = $this->customerRequest();
        $request->headers->set(
            'Authorization',
            'Bearer test-token'
        );

        $auth = $this->authWithClaims([
            'sub' => 'test-customer',
        ]);

        $response = $this->executeWithNoFirestore(
            $request,
            $auth
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_rejects_authentication_time_in_distant_future(): void
    {
        $request = $this->customerRequest();
        $request->headers->set(
            'Authorization',
            'Bearer test-token'
        );

        $auth = $this->authWithClaims([
            'sub' => 'test-customer',
            'auth_time' => time() + 600,
        ]);

        $response = $this->executeWithNoFirestore(
            $request,
            $auth
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    private function customerRequest(
        string $uid = 'test-customer',
        string $role = 'customer'
    ): Request {
        $request = Request::create(
            '/api/users/me',
            'DELETE'
        );

        $request->attributes->set('firebase_uid', $uid);

        $request->attributes->set('firebase_profile', [
            'role' => $role,
            'status' => 'active',
        ]);

        return $request;
    }

    private function authWithClaims(array $claims): Auth
    {
        $auth = Mockery::mock(Auth::class);

        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->andReturn(new DataSet($claims, ''));

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('test-token', true)
            ->andReturn($token);

        $auth->shouldNotReceive('deleteUser');

        return $auth;
    }

    private function assertRejectedWithoutFirestore(
        Request $request,
        int $expectedStatus
    ): void {
        $auth = Mockery::mock(Auth::class);
        $firestore = Mockery::mock(FirestoreClient::class);

        $auth->shouldNotReceive('deleteUser');
        $firestore->shouldNotReceive('collection');

        $response = (new DeleteAccountController())->destroy(
            $request,
            $auth,
            $firestore
        );

        $this->assertSame(
            $expectedStatus,
            $response->getStatusCode()
        );
    }

    private function executeWithNoFirestore(
        Request $request,
        Auth $auth
    ): \Illuminate\Http\JsonResponse {
        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldNotReceive('collection');

        return (new DeleteAccountController())->destroy(
            $request,
            $auth,
            $firestore
        );
    }
}
