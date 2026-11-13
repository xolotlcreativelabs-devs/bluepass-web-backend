<?php

namespace Tests\Feature;

use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\EmailNotFound;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    /**
     * Exigir el correo electrónico.
     */
    public function test_password_reset_requires_email(): void
    {
        $this->postJson('/api/auth/password-reset', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Rechazar correos con formato incorrecto.
     */
    public function test_password_reset_rejects_invalid_email(): void
    {
        $this->postJson('/api/auth/password-reset', [
            'email' => 'correo-invalido',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Solicitar a Firebase el envío del correo.
     */
    public function test_password_reset_sends_email_request(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('sendPasswordResetLink')
            ->once()
            ->with('cliente@ejemplo.com');

        $this->app->instance(Auth::class, $auth);

        $this->postJson('/api/auth/password-reset', [
            'email' => 'cliente@ejemplo.com',
        ])
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Si existe una cuenta asociada al correo, recibirás instrucciones para recuperar tu acceso.'
            );
    }

    /**
     * No revelar si el correo está registrado.
     */
    public function test_password_reset_does_not_reveal_unknown_email(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('sendPasswordResetLink')
            ->once()
            ->with('desconocido@ejemplo.com')
            ->andThrow(new EmailNotFound('Correo no encontrado.'));

        $this->app->instance(Auth::class, $auth);

        $this->postJson('/api/auth/password-reset', [
            'email' => 'desconocido@ejemplo.com',
        ])
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Si existe una cuenta asociada al correo, recibirás instrucciones para recuperar tu acceso.'
            );
    }

    /**
     * No exponer errores internos de Firebase.
     */
    public function test_password_reset_hides_internal_errors(): void
    {
        $auth = Mockery::mock(Auth::class);

        $auth->shouldReceive('sendPasswordResetLink')
            ->once()
            ->with('cliente@ejemplo.com')
            ->andThrow(new RuntimeException('Error interno de prueba.'));

        $this->app->instance(Auth::class, $auth);

        $response = $this->postJson('/api/auth/password-reset', [
            'email' => 'cliente@ejemplo.com',
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath(
                'message',
                'Si existe una cuenta asociada al correo, recibirás instrucciones para recuperar tu acceso.'
            );

        $this->assertStringNotContainsString(
            'Error interno de prueba.',
            $response->getContent()
        );
    }
}
