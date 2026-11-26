<?php

namespace Tests\Feature;

use App\Services\MultiOrderReservationValidator;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Tests\TestCase;

class MultiOrderReservationValidatorTest extends TestCase
{
    private MultiOrderReservationValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new MultiOrderReservationValidator();
    }

    /**
     * Crear una seleccion de dos VIP y tres Generales.
     */
    private function selection(): array
    {
        return [
            'items' => [
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 2,
                ],
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 3,
                ],
            ],
        ];
    }

    /**
     * Preparar una reserva multiple vigente.
     * Los precios estan expresados en centavos.
     */
    private function reservation(): array
    {
        $now = new DateTimeImmutable();

        return [
            'eventId' => 'evento-prueba',
            'userId' => 'comprador-prueba',
            'quantity' => 5,
            'currency' => 'MXN',
            'status' => 'active',
            'expiresAt' => new Timestamp(
                $now->modify('+10 minutes')
            ),
            'items' => [
                [
                    'ticketTypeId' => 'general',
                    'quantity' => 3,
                    'unitPrice' => 50000,
                    'currency' => 'MXN',
                ],
                [
                    'ticketTypeId' => 'vip',
                    'quantity' => 2,
                    'unitPrice' => 150000,
                    'currency' => 'MXN',
                ],
            ],
        ];
    }

    /**
     * Validar una reserva que coincide con el pedido.
     */
    public function test_accepts_matching_multiple_reservation(): void
    {
        $result = $this->validator->validate(
            $this->selection(),
            $this->reservation(),
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertTrue($result['valid']);
        $this->assertSame(200, $result['code']);
        $this->assertSame(5, $result['quantity']);
        $this->assertSame('MXN', $result['currency']);
        $this->assertCount(2, $result['items']);

        $this->assertSame([
            [
                'ticketTypeId' => 'general',
                'quantity' => 3,
                'unitPrice' => 50000,
                'currency' => 'MXN',
            ],
            [
                'ticketTypeId' => 'vip',
                'quantity' => 2,
                'unitPrice' => 150000,
                'currency' => 'MXN',
            ],
        ], $result['items']);
    }

    /**
     * Impedir cambiar las cantidades de una reserva.
     */
    public function test_rejects_different_quantities(): void
    {
        $selection = $this->selection();

        $selection['items'][0]['quantity'] = 3;
        $selection['items'][1]['quantity'] = 2;

        $result = $this->validator->validate(
            $selection,
            $this->reservation(),
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    /**
     * Impedir utilizar una reserva de otro comprador.
     */
    public function test_rejects_another_users_reservation(): void
    {
        $result = $this->validator->validate(
            $this->selection(),
            $this->reservation(),
            'otro-comprador',
            'evento-prueba'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(403, $result['code']);
    }

    /**
     * Rechazar una reserva de otro evento.
     */
    public function test_rejects_different_event(): void
    {
        $result = $this->validator->validate(
            $this->selection(),
            $this->reservation(),
            'comprador-prueba',
            'otro-evento'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    /**
     * Rechazar reservas que ya vencieron.
     */
    public function test_rejects_expired_reservation(): void
    {
        $reservation = $this->reservation();

        $reservation['expiresAt'] = new Timestamp(
            new DateTimeImmutable('-1 minute')
        );

        $result = $this->validator->validate(
            $this->selection(),
            $reservation,
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    /**
     * Rechazar reservas canceladas.
     */
    public function test_rejects_cancelled_reservation(): void
    {
        $reservation = $this->reservation();
        $reservation['status'] = 'cancelled';

        $result = $this->validator->validate(
            $this->selection(),
            $reservation,
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    /**
     * Rechazar reservas ya confirmadas.
     */
    public function test_rejects_confirmed_reservation(): void
    {
        $reservation = $this->reservation();
        $reservation['status'] = 'confirmed';

        $result = $this->validator->validate(
            $this->selection(),
            $reservation,
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    /**
     * Rechazar reservas sin una lista de boletos.
     */
    public function test_rejects_reservation_without_items(): void
    {
        $reservation = $this->reservation();

        unset($reservation['items']);

        $result = $this->validator->validate(
            $this->selection(),
            $reservation,
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    /**
     * Rechazar reservas con cantidades inconsistentes.
     */
    public function test_rejects_incorrect_reserved_total(): void
    {
        $reservation = $this->reservation();
        $reservation['quantity'] = 6;

        $result = $this->validator->validate(
            $this->selection(),
            $reservation,
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    /**
     * Rechazar precios negativos registrados en la reserva.
     */
    public function test_rejects_negative_unit_price(): void
    {
        $reservation = $this->reservation();
        $reservation['items'][0]['unitPrice'] = -100;

        $result = $this->validator->validate(
            $this->selection(),
            $reservation,
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    /**
     * Rechazar precios mayores al limite permitido.
     */
    public function test_rejects_unit_price_above_limit(): void
    {
        $reservation = $this->reservation();

        $reservation['items'][0]['unitPrice'] = 10000001;

        $result = $this->validator->validate(
            $this->selection(),
            $reservation,
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    /**
     * Rechazar monedas distintas de MXN.
     */
    public function test_rejects_invalid_currency(): void
    {
        $reservation = $this->reservation();
        $reservation['currency'] = 'USD';

        $result = $this->validator->validate(
            $this->selection(),
            $reservation,
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    /**
     * Rechazar una moneda incorrecta en una categoria.
     */
    public function test_rejects_invalid_item_currency(): void
    {
        $reservation = $this->reservation();

        $reservation['items'][0]['currency'] = 'USD';

        $result = $this->validator->validate(
            $this->selection(),
            $reservation,
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    /**
     * Rechazar categorias repetidas en la reserva.
     */
    public function test_rejects_duplicate_reserved_types(): void
    {
        $reservation = $this->reservation();

        $reservation['items'][1]['ticketTypeId'] = 'general';

        $result = $this->validator->validate(
            $this->selection(),
            $reservation,
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(409, $result['code']);
    }

    /**
     * El orden de seleccion no debe afectar la validacion.
     */
    public function test_accepts_different_selection_order(): void
    {
        $selection = $this->selection();

        $selection['items'] = array_reverse(
            $selection['items']
        );

        $result = $this->validator->validate(
            $selection,
            $this->reservation(),
            'comprador-prueba',
            'evento-prueba'
        );

        $this->assertTrue($result['valid']);
        $this->assertSame(200, $result['code']);
        $this->assertSame(5, $result['quantity']);
    }
}
