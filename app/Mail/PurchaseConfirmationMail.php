<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PurchaseConfirmationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Preparar los datos del correo.
     */
    public function __construct(
        public string $buyerName,
        public string $orderId,
        public int $ticketCount
    ) {
    }

    /**
     * Construir el mensaje de confirmacion.
     */
    public function build(): self
    {
        return $this
            ->subject('BluePass - Confirmacion de compra')
            ->view('emails.purchase-confirmation')
            ->with([
                'buyerName' => $this->buyerName,
                'orderId' => $this->orderId,
                'ticketCount' => $this->ticketCount,
            ]);
    }
}
