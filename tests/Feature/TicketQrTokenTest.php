<?php

namespace Tests\Feature;

use App\Services\TicketQrToken;
use InvalidArgumentException;
use Tests\TestCase;

class TicketQrTokenTest extends TestCase
{
    private TicketQrToken $qrToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qrToken = new TicketQrToken();
    }

    public function test_generates_valid_token(): void
    {
        $token = $this->qrToken->generate();

        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $token
        );
    }

    public function test_generates_different_tokens(): void
    {
        $first = $this->qrToken->generate();
        $second = $this->qrToken->generate();

        $this->assertNotSame($first, $second);
    }

    public function test_generates_valid_hash(): void
    {
        $token = $this->qrToken->generate();
        $hash = $this->qrToken->hash($token);

        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $hash
        );

        $this->assertNotSame($token, $hash);
    }

    public function test_same_token_generates_same_hash(): void
    {
        $token = $this->qrToken->generate();

        $first = $this->qrToken->hash($token);
        $second = $this->qrToken->hash($token);

        $this->assertSame($first, $second);
    }

    public function test_verifies_correct_token(): void
    {
        $token = $this->qrToken->generate();
        $hash = $this->qrToken->hash($token);

        $this->assertTrue(
            $this->qrToken->verify($token, $hash)
        );
    }

    public function test_rejects_incorrect_token(): void
    {
        $token = $this->qrToken->generate();
        $otherToken = $this->qrToken->generate();

        $hash = $this->qrToken->hash($token);

        $this->assertFalse(
            $this->qrToken->verify($otherToken, $hash)
        );
    }

    public function test_rejects_modified_token(): void
    {
        $token = $this->qrToken->generate();
        $hash = $this->qrToken->hash($token);

        $modifiedToken = (
            $token[0] === 'a' ? 'b' : 'a'
        ) . substr($token, 1);

        $this->assertFalse(
            $this->qrToken->verify($modifiedToken, $hash)
        );
    }

    public function test_rejects_empty_token(): void
    {
        $this->assertFalse(
            $this->qrToken->verify(
                '',
                str_repeat('a', 64)
            )
        );
    }

    public function test_rejects_invalid_hash(): void
    {
        $token = $this->qrToken->generate();

        $this->assertFalse(
            $this->qrToken->verify(
                $token,
                'invalid-hash'
            )
        );
    }

    public function test_rejects_uppercase_token(): void
    {
        $token = strtoupper(
            $this->qrToken->generate()
        );

        $this->assertFalse(
            $this->qrToken->verify(
                $token,
                str_repeat('a', 64)
            )
        );
    }

    public function test_hash_rejects_invalid_token(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->qrToken->hash('invalid-token');
    }

    public function test_hash_rejects_empty_token(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->qrToken->hash('');
    }
}
