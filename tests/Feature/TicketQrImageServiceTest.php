<?php

namespace Tests\Feature;

use App\Services\TicketQrImageService;
use InvalidArgumentException;
use Tests\TestCase;

class TicketQrImageServiceTest extends TestCase
{
    private TicketQrImageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TicketQrImageService();
    }

    public function test_generates_svg_image(): void
    {
        $svg = $this->service->generate(
            'BLUEPASS-BOLETO-PRUEBA'
        );

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertGreaterThan(100, strlen($svg));
    }

    public function test_generates_different_images_for_different_tokens(): void
    {
        $first = $this->service->generate(
            'BLUEPASS-PRUEBA-UNO'
        );

        $second = $this->service->generate(
            'BLUEPASS-PRUEBA-DOS'
        );

        $this->assertNotSame($first, $second);
    }

    public function test_same_token_generates_same_image(): void
    {
        $first = $this->service->generate(
            'BLUEPASS-PRUEBA-IGUAL'
        );

        $second = $this->service->generate(
            'BLUEPASS-PRUEBA-IGUAL'
        );

        $this->assertSame($first, $second);
    }

    public function test_generates_svg_data_uri(): void
    {
        $uri = $this->service->dataUri(
            'BLUEPASS-BOLETO-PRUEBA'
        );

        $this->assertStringStartsWith(
            'data:image/svg+xml;base64,',
            $uri
        );

        $encoded = substr(
            $uri,
            strlen('data:image/svg+xml;base64,')
        );

        $svg = base64_decode($encoded, true);

        $this->assertNotFalse($svg);
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_rejects_empty_token(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service->generate('');
    }

    public function test_rejects_token_longer_than_limit(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service->generate(
            str_repeat('A', 513)
        );
    }

    public function test_accepts_token_at_limit(): void
    {
        $svg = $this->service->generate(
            str_repeat('A', 512)
        );

        $this->assertStringContainsString('<svg', $svg);
    }
}
