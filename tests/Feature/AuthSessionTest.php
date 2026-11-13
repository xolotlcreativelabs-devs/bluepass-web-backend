<?php

namespace Tests\Feature;

use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class AuthSessionTest extends TestCase
{
    /**
     * Comprobar que el inicio de sesión requiere un token de Firebase.
     */
    public function test_session_requires_a_firebase_token(): void
    {
        $response = $this->postJson('/api/auth/session', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['idToken']);
    }

    /**
     * Comprobar que Laravel rechaza tokens inválidos.
     */
    public function test_session_rejects_an_invalid_token(): void
    {
        $response = $this->postJson('/api/auth/session', [
            'idToken' => 'token-invalido-de-prueba',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Comprobar que un token revocado devuelve HTTP 401 y no HTTP 500.
     */
    public function test_session_rejects_a_revoked_firebase_token(): void
    {
        // Crear un token ficticio para simular la revocación.
        $token = Mockery::mock(UnencryptedToken::class);

        // Simular Firebase Authentication sin conectar con usuarios reales.
        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('token-revocado-de-prueba', true)
            ->andThrow(
                new RevokedIdToken($token)
            );

        // Sustituir Firebase Authentication por la simulación.
        $this->app->instance(Auth::class, $auth);

        // Comprobar que Laravel rechaza el token revocado.
        $this->postJson('/api/auth/session', [
            'idToken' => 'token-revocado-de-prueba',
        ])
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Token de autenticación inválido, expirado o revocado.',
            ]);
    }
}
