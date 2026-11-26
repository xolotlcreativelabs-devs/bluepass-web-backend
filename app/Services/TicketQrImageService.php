<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;

class TicketQrImageService
{
    /**
     * Generar una imagen SVG para una credencial QR.
     */
    public function generate(string $qrValue): string
    {
        if (
            $qrValue === '' ||
            strlen($qrValue) > 512
        ) {
            throw new InvalidArgumentException(
                'La credencial QR no es valida.'
            );
        }

        $renderer = new ImageRenderer(
            new RendererStyle(320, 4),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);

        return $writer->writeString($qrValue);
    }

    /**
     * Preparar una imagen para mostrarla en el boleto.
     */
    public function dataUri(string $qrValue): string
    {
        $svg = $this->generate($qrValue);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
