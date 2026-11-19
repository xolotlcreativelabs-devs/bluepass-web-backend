<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\EventStatusController;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class EventTimezoneComparisonTest extends TestCase
{
    /**
     * Aceptar fechas con diferentes desfases UTC.
     */
    public function test_accepts_dates_with_different_utc_offsets(): void
    {
        $event = $this->makeEvent(
            '2026-12-10T18:00:00-06:00',
            '2026-12-11T03:00:00+00:00'
        );

        $this->assertTrue($this->hasValidDates($event));
    }

    /**
     * Rechazar una finalización anterior en tiempo real.
     */
    public function test_rejects_end_before_start_across_offsets(): void
    {
        $event = $this->makeEvent(
            '2026-12-10T18:00:00-06:00',
            '2026-12-10T23:00:00+00:00'
        );

        $this->assertFalse($this->hasValidDates($event));
    }

    /**
     * Rechazar fechas que representan el mismo instante.
     */
    public function test_rejects_same_instant_with_different_offsets(): void
    {
        $event = $this->makeEvent(
            '2026-12-10T18:00:00-06:00',
            '2026-12-11T00:00:00+00:00'
        );

        $this->assertFalse($this->hasValidDates($event));
    }

    /**
     * Permitir una zona horaria diferente del desfase de las fechas.
     */
    public function test_accepts_utc_dates_with_mexico_timezone(): void
    {
        $event = $this->makeEvent(
            '2026-12-11T00:00:00+00:00',
            '2026-12-11T04:00:00+00:00'
        );

        $this->assertTrue($this->hasValidDates($event));
    }

    /**
     * Preparar un evento con fechas almacenadas en Firestore.
     */
    private function makeEvent(string $start, string $end): array
    {
        return [
            'timezone' => 'America/Mexico_City',
            'date' => new Timestamp(new DateTimeImmutable($start)),
            'endAt' => new Timestamp(new DateTimeImmutable($end)),
        ];
    }

    /**
     * Ejecutar la validación real del controlador.
     */
    private function hasValidDates(array $event): bool
    {
        $controller = new EventStatusController();

        $method = new ReflectionMethod(
            EventStatusController::class,
            'hasValidDates'
        );

        return $method->invoke($controller, $event);
    }
}
