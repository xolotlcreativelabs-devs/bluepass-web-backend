<?php

namespace App\Services;

use InvalidArgumentException;

class TicketQrToken
{
    /**
     * Generar un token aleatorio para un boleto.
     */
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Guardar solamente la huella del token.
     */
    public function hash(string $token): string
    {
        if (!$this->validToken($token)) {
            throw new InvalidArgumentException(
                'El token del boleto no es valido.'
            );
        }

        return hash('sha256', $token);
    }

    /**
     * Comprobar un token contra su huella almacenada.
     */
    public function verify(
        string $token,
        string $storedHash
    ): bool {
        if (
            !$this->validToken($token) ||
            !preg_match('/^[a-f0-9]{64}$/D', $storedHash)
        ) {
            return false;
        }

        return hash_equals(
            $storedHash,
            hash('sha256', $token)
        );
    }

    /**
     * Validar el formato del token.
     */
    private function validToken(string $token): bool
    {
        return preg_match(
            '/^[a-f0-9]{64}$/D',
            $token
        ) === 1;
    }
}
