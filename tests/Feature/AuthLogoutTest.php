<?php

namespace Tests\Feature;

use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class AuthLogoutTest extends TestCase
{
    /**
     * Comprobar que el cierre de sesión requiere un token.
     */
    public function test_logout_requires_a_firebase_token(): void
    {
        $this->postJson('/api/auth/logout', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idToken']);
    }

    /**
     * Rechazar tokens inválidos o expirados.
     */
    public function test_logout_rejects_an_invalid_token(): void
    {
        $this->postJson('/api/auth/logout', [
            'idToken' => 'token-invalido-de-prueba',
        ])
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Token de autenticación inválido, expirado o revocado.',
            ]);
    }

    /**
     * Revocar las sesiones del usuario identificado por Firebase.
     */
    public function test_logout_revokes_the_authenticated_users_sessions(): void
    {
        $uid = 'firebase-uid-prueba';

        // Simular un token verificado por Firebase.
        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->once()
            ->andReturn(new DataSet([
                'sub' => $uid,
            ], ''));

        // Simular Firebase Authentication sin utilizar cuentas reales.
        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-ficticio', true)
            ->andReturn($token);

        // Revocar únicamente las sesiones del UID autenticado.
        $auth->shouldReceive('revokeRefreshTokens')
            ->once()
            ->with($uid);

        $this->app->instance(Auth::class, $auth);

        $this->postJson('/api/auth/logout', [
            'idToken' => 'token-ficticio',
        ])
            ->assertStatus(200)
            ->assertJson([
                'message' => 'Se han revocado las sesiones del usuario.',
            ]);
    }

    /**
     * Rechazar tokens que no contienen un UID válido.
     */
    public function test_logout_rejects_a_token_without_a_valid_uid(): void
    {
        // Simular un token sin identificador válido.
        $token = Mockery::mock(UnencryptedToken::class);

        $token->shouldReceive('claims')
            ->once()
            ->andReturn(new DataSet([
                'sub' => '',
            ], ''));

        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-ficticio', true)
            ->andReturn($token);

        // No revocar sesiones si no se identifica al usuario.
        $auth->shouldNotReceive('revokeRefreshTokens');

        $this->app->instance(Auth::class, $auth);

        $this->postJson('/api/auth/logout', [
            'idToken' => 'token-ficticio',
        ])
            ->assertStatus(401)
            ->assertJson([
                'message' => 'No se pudo identificar al usuario.',
            ]);
    }

    /**
     * Comprobar que un token revocado recibe HTTP 401.
     */
    public function test_logout_rejects_a_revoked_firebase_token(): void
    {
        // Crear un token ficticio para simular la revocación.
        $token = Mockery::mock(UnencryptedToken::class);

        // Simular Firebase Authentication.
        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-revocado-de-prueba', true)
            ->andThrow(
                new RevokedIdToken($token)
            );

        // No intentar revocar sesiones si el token fue rechazado.
        $auth->shouldNotReceive('revokeRefreshTokens');

        $this->app->instance(Auth::class, $auth);

        // Verificar que Laravel responda HTTP 401, no HTTP 500.
        $this->postJson('/api/auth/logout', [
            'idToken' => 'token-revocado-de-prueba',
        ])
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Token de autenticación inválido, expirado o revocado.',
            ]);
    }
}
