<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    /**
     * Permitir hasta 10 solicitudes por minuto
     * desde una misma dirección IP.
     */
    public function test_auth_allows_ten_requests_per_minute(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.10',
        ]);

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/auth/session', [])
                ->assertStatus(422);
        }
    }

    /**
     * Bloquear la solicitud número 11
     * desde la misma dirección IP.
     */
    public function test_auth_blocks_requests_above_the_limit(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.20',
        ]);

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/auth/session', [])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/session', [])
            ->assertStatus(429);
    }

    /**
     * No bloquear otra dirección IP
     * por las solicitudes de la primera.
     */
    public function test_auth_uses_separate_limits_for_different_ips(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.30',
        ]);

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/auth/session', [])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/session', [])
            ->assertStatus(429);

        // Una IP diferente debe conservar su propio límite.
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.31',
        ]);

        $this->postJson('/api/auth/session', [])
            ->assertStatus(422);
    }

    /**
     * Comprobar que registro, inicio y cierre
     * de sesión comparten el mismo límite.
     */
    public function test_auth_endpoints_share_the_same_rate_limit(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.40',
        ]);

        // Cuatro solicitudes de registro.
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/auth/register', [])
                ->assertStatus(422);
        }

        // Tres solicitudes de inicio de sesión.
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/session', [])
                ->assertStatus(422);
        }

        // Tres solicitudes de cierre de sesión.
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/logout', [])
                ->assertStatus(422);
        }

        // Se alcanzaron 10 solicitudes en total.
        // La siguiente debe rechazarse.
        $this->postJson('/api/auth/register', [])
            ->assertStatus(429);
    }
}
