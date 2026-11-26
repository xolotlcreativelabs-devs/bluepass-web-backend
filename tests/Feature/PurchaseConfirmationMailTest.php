<?php

namespace Tests\Feature;

use App\Mail\PurchaseConfirmationMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PurchaseConfirmationMailTest extends TestCase
{
    /**
     * El correo debe mostrar los datos de la compra.
     */
    public function test_mail_contains_purchase_information(): void
    {
        $mail = new PurchaseConfirmationMail(
            'Comprador de prueba',
            'pedido-bluepass-123',
            2
        );

        $html = $mail->render();

        $this->assertStringContainsString(
            'Comprador de prueba',
            $html
        );

        $this->assertStringContainsString(
            'pedido-bluepass-123',
            $html
        );

        $this->assertStringContainsString(
            'Boletos adquiridos',
            $html
        );

        $this->assertStringContainsString(
            'Mis boletos',
            $html
        );

        $this->assertStringContainsString(
            'BluePass',
            $html
        );
    }

    /**
     * El correo debe indicar que la compra fue confirmada.
     */
    public function test_mail_has_confirmation_subject(): void
    {
        $mail = new PurchaseConfirmationMail(
            'Comprador de prueba',
            'pedido-bluepass-456',
            1
        );

        $mail->build();

        $this->assertSame(
            'BluePass - Confirmacion de compra',
            $mail->subject
        );

        $this->assertSame(
            'pedido-bluepass-456',
            $mail->orderId
        );

        $this->assertSame(
            1,
            $mail->ticketCount
        );
    }

    /**
     * No mostrar tokens ni referencias privadas.
     */
    public function test_mail_does_not_expose_private_fields(): void
    {
        $mail = new PurchaseConfirmationMail(
            'Comprador de prueba',
            'pedido-bluepass-789',
            2
        );

        $html = $mail->render();

        $this->assertStringNotContainsString(
            'qrTokenEncrypted',
            $html
        );

        $this->assertStringNotContainsString(
            'qrTokenHash',
            $html
        );

        $this->assertStringNotContainsString(
            'paymentReference',
            $html
        );

        $this->assertStringNotContainsString(
            'service-account',
            $html
        );
    }

    /**
     * Escapar el nombre del comprador en la plantilla.
     */
    public function test_mail_escapes_buyer_name(): void
    {
        $mail = new PurchaseConfirmationMail(
            '<script>alert("test")</script>',
            'pedido-bluepass-999',
            1
        );

        $html = $mail->render();

        $this->assertStringNotContainsString(
            '<script>',
            $html
        );

        $this->assertStringContainsString(
            '&lt;script&gt;',
            $html
        );
    }

    /**
     * Simular el envio sin contactar proveedores externos.
     */
    public function test_mail_can_be_sent_using_fake(): void
    {
        Mail::fake();

        Mail::to('comprador@example.com')->send(
            new PurchaseConfirmationMail(
                'Comprador de prueba',
                'pedido-bluepass-fake',
                2
            )
        );

        Mail::assertSent(
            PurchaseConfirmationMail::class,
            function (PurchaseConfirmationMail $mail): bool {
                return $mail->hasTo(
                    'comprador@example.com'
                ) &&
                    $mail->orderId ===
                        'pedido-bluepass-fake' &&
                    $mail->ticketCount === 2;
            }
        );

        Mail::assertSentCount(1);
    }

    /**
     * Sin una solicitud de envio no debe registrarse correo.
     */
    public function test_mail_is_not_sent_automatically(): void
    {
        Mail::fake();

        new PurchaseConfirmationMail(
            'Comprador de prueba',
            'pedido-sin-envio',
            2
        );

        Mail::assertNothingSent();
    }
}
