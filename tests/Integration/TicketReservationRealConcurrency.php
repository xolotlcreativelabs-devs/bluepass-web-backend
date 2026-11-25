<?php

declare(strict_types=1);

use App\Http\Controllers\Api\TicketReservationController;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

$backendPath = dirname(__DIR__, 2);

require_once $backendPath . '/vendor/autoload.php';

/**
 * Verificar que se utiliza exclusivamente el emulador.
 */
function verifyEmulator(): void
{
    $host = getenv('FIRESTORE_EMULATOR_HOST');
    $project = getenv('GOOGLE_CLOUD_PROJECT');

    if (
        $host !== '127.0.0.1:8080' ||
        $project !== 'demo-bluepass-hu04'
    ) {
        throw new RuntimeException(
            'La prueba solo puede ejecutarse con el emulador de BluePass.'
        );
    }

    $url = 'http://127.0.0.1:8080/v1/projects/'
        . 'demo-bluepass-hu04/databases/(default)/documents/events';

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);

    $status = $http_response_header[0] ?? '';

    if (
        $result === false ||
        !str_contains($status, '200')
    ) {
        throw new RuntimeException(
            'El emulador de Firestore no esta disponible.'
        );
    }
}

/**
 * Crear un cliente exclusivo para el emulador.
 */
function emulatorClient(): FirestoreClient
{
    verifyEmulator();

    return new FirestoreClient([
        'projectId' => 'demo-bluepass-hu04',
        'transport' => 'rest',
    ]);
}

/**
 * Inicializar Laravel dentro de cada proceso comprador.
 */
function bootLaravel(): void
{
    global $backendPath;

    $app = require $backendPath . '/bootstrap/app.php';

    $app->make(Kernel::class)->bootstrap();
}

/**
 * Ejecutar una solicitud real de reserva.
 */
function runBuyer(
    string $eventId,
    string $buyerId,
    float $startAt
): void {
    try {
        verifyEmulator();

        bootLaravel();

        $firestore = emulatorClient();

        // Esperar hasta el momento compartido de inicio.
        while (microtime(true) < $startAt) {
            usleep(1000);
        }

        $request = Request::create(
            '/api/events/' . $eventId . '/reservations',
            'POST',
            [
                'ticketTypeId' => 'general',
                'quantity' => 1,
            ]
        );

        $request->attributes->set(
            'firebase_uid',
            $buyerId
        );

        $request->attributes->set(
            'firebase_role',
            'customer'
        );

        $controller = new TicketReservationController();

        $response = $controller->store(
            $request,
            $eventId,
            $firestore
        );

        echo json_encode([
            'buyer' => $buyerId,
            'statusCode' => $response->getStatusCode(),
            'response' => $response->getData(true),
        ], JSON_THROW_ON_ERROR);

        exit(0);
    } catch (Throwable $exception) {
        echo json_encode([
            'buyer' => $buyerId,
            'statusCode' => 500,
            'error' => $exception->getMessage(),
        ]);

        exit(1);
    }
}

/**
 * Preparar un evento de prueba con un solo boleto.
 */
function createTestEvent(
    FirestoreClient $firestore
): array {
    $eventId = 'concurrencia-hu04-'
        . bin2hex(random_bytes(8));

    $eventDocument = $firestore
        ->collection('events')
        ->document($eventId);

    $ticketDocument = $eventDocument
        ->collection('ticketTypes')
        ->document('general');

    $now = new DateTimeImmutable();

    $eventDocument->set([
        'name' => 'Prueba de concurrencia BluePass',
        'status' => 'published',
        'date' => new Timestamp(
            $now->modify('+10 days')
        ),
    ]);

    $ticketDocument->set([
        'name' => 'General',
        'status' => 'active',
        'price' => 350,
        'capacity' => 1,
        'sold' => 0,
        'reserved' => 0,
        'saleStartAt' => new Timestamp(
            $now->modify('-1 hour')
        ),
        'saleEndAt' => new Timestamp(
            $now->modify('+5 days')
        ),
    ]);

    return [
        'eventId' => $eventId,
        'eventDocument' => $eventDocument,
        'ticketDocument' => $ticketDocument,
    ];
}

/**
 * Eliminar exclusivamente el evento creado para esta prueba.
 */
function deleteTestEvent(array $testEvent): void
{
    $eventDocument = $testEvent['eventDocument'];

    foreach (
        $eventDocument
            ->collection('reservations')
            ->documents() as $reservation
    ) {
        if ($reservation->exists()) {
            $reservation->reference()->delete();
        }
    }

    $testEvent['ticketDocument']->delete();

    $eventDocument->delete();
}

/**
 * Iniciar dos procesos PHP independientes.
 */
function startBuyers(
    string $eventId
): array {
    $buyers = [
        'comprador-concurrencia-uno',
        'comprador-concurrencia-dos',
    ];

    $processes = [];

    // Los dos procesos utilizaran el mismo momento de inicio.
    $startAt = microtime(true) + 4;

    foreach ($buyers as $buyer) {
        $command = [
            PHP_BINARY,
            __FILE__,
            '--worker',
            $eventId,
            $buyer,
            (string) $startAt,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            dirname(__DIR__, 2)
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                'No fue posible iniciar el proceso ' . $buyer
            );
        }

        fclose($pipes[0]);

        $processes[] = [
            'buyer' => $buyer,
            'process' => $process,
            'pipes' => $pipes,
        ];
    }

    $results = [];

    foreach ($processes as $item) {
        $output = stream_get_contents($item['pipes'][1]);
        $errors = stream_get_contents($item['pipes'][2]);

        fclose($item['pipes'][1]);
        fclose($item['pipes'][2]);

        $exitCode = proc_close($item['process']);

        $decoded = json_decode(
            $output,
            true
        );

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'El proceso ' . $item['buyer']
                . ' no devolvio un resultado valido. '
                . $errors
            );
        }

        $decoded['processExitCode'] = $exitCode;

        $results[] = $decoded;
    }

    return $results;
}

/**
 * Ejecutar y comprobar la concurrencia.
 */
function runConcurrencyTest(): void
{
    $firestore = emulatorClient();

    $testEvent = createTestEvent($firestore);

    try {
        echo PHP_EOL;
        echo 'BLUEPASS - PRUEBA REAL DE CONCURRENCIA' . PHP_EOL;
        echo 'Proyecto: demo-bluepass-hu04' . PHP_EOL;
        echo 'Capacidad inicial: 1 boleto' . PHP_EOL;
        echo PHP_EOL;

        $results = startBuyers(
            $testEvent['eventId']
        );

        $statusCodes = [];

        foreach ($results as $result) {
            $statusCodes[] = $result['statusCode'];

            echo $result['buyer'] . ': HTTP '
                . $result['statusCode'] . PHP_EOL;

            echo 'Respuesta: '
                . json_encode(
                    $result['response']
                    ?? $result['error']
                    ?? null,
                    JSON_UNESCAPED_UNICODE
                )
                . PHP_EOL;
        }

        sort($statusCodes);

        $inventory = $testEvent['ticketDocument']
            ->snapshot()
            ->data();

        $reservations = [];

        foreach (
            $testEvent['eventDocument']
                ->collection('reservations')
                ->documents() as $document
        ) {
            if ($document->exists()) {
                $reservations[] = $document->data();
            }
        }

        $activeReservations = array_values(
            array_filter(
                $reservations,
                fn (array $reservation): bool =>
                    ($reservation['status'] ?? null) === 'active'
            )
        );

        $capacity = $inventory['capacity'];
        $sold = $inventory['sold'];
        $reserved = $inventory['reserved'];

        $available = $capacity - $sold - $reserved;

        echo PHP_EOL;
        echo 'INVENTARIO FINAL' . PHP_EOL;
        echo 'Capacidad: ' . $capacity . PHP_EOL;
        echo 'Vendidos: ' . $sold . PHP_EOL;
        echo 'Reservados: ' . $reserved . PHP_EOL;
        echo 'Disponibles: ' . $available . PHP_EOL;
        echo 'Reservas activas: '
            . count($activeReservations) . PHP_EOL;

        $validResponses = $statusCodes === [201, 409];

        $validInventory = (
            $capacity === 1 &&
            $sold === 0 &&
            $reserved === 1 &&
            $available === 0
        );

        $validReservations = (
            count($activeReservations) === 1 &&
            ($activeReservations[0]['quantity'] ?? null) === 1
        );

        if (
            !$validResponses ||
            !$validInventory ||
            !$validReservations
        ) {
            throw new RuntimeException(
                'La prueba de concurrencia no cumplio '
                . 'todos los resultados esperados.'
            );
        }

        echo PHP_EOL;
        echo 'RESULTADO: PRUEBA APROBADA' . PHP_EOL;
        echo 'No se detectaron sobreventas.' . PHP_EOL;
    } finally {
        deleteTestEvent($testEvent);

        echo PHP_EOL;
        echo 'Limpieza de datos temporales finalizada.'
            . PHP_EOL;
    }
}

// Distinguir el proceso principal de los compradores.
if (($argv[1] ?? null) === '--worker') {
    runBuyer(
        $argv[2] ?? '',
        $argv[3] ?? '',
        (float) ($argv[4] ?? 0)
    );

    exit(0);
}

try {
    runConcurrencyTest();
    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'ERROR: ' . $exception->getMessage() . PHP_EOL
    );

    exit(1);
}
