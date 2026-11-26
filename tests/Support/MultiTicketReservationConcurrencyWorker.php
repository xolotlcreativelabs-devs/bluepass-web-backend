<?php

declare(strict_types=1);

use App\Services\MultiTicketReservationService;
use App\Services\MultiTicketSelectionValidator;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Estas pruebas solo pueden ejecutarse contra el emulador.
if (
    getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
    getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
) {
    fwrite(
        STDERR,
        "Esta prueba requiere Firestore Emulator.\n"
    );

    exit(1);
}

if (count($argv) !== 3) {
    fwrite(
        STDERR,
        "Uso: php MultiTicketReservationConcurrencyWorker.php evento comprador\n"
    );

    exit(1);
}

[, $eventId, $userId] = $argv;

try {
    // Inicializar Laravel para utilizar sus validadores.
    $app = require dirname(__DIR__, 2)
        . '/bootstrap/app.php';

    $app->make(Kernel::class)->bootstrap();

    $firestore = new FirestoreClient([
        'projectId' => 'demo-bluepass-hu04',
        'transport' => 'rest',
    ]);

    $service = new MultiTicketReservationService(
        $firestore,
        new MultiTicketSelectionValidator()
    );

    // Ambos compradores solicitan el mismo inventario.
    $result = $service->create(
        $eventId,
        $userId,
        [
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
        ]
    );

    echo json_encode(
        [
            'success' => ($result['code'] ?? null) === 201,
            'code' => $result['code'] ?? null,
            'userId' => $userId,
            'reservationId' =>
                $result['reservation']['id'] ?? null,
            'message' => $result['message'] ?? null,
            'errorClass' => null,
        ],
        JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (Throwable $exception) {
    // Conservar los errores de concurrencia para diagnosticarlos.
    echo json_encode(
        [
            'success' => false,
            'code' => null,
            'userId' => $userId,
            'reservationId' => null,
            'message' => $exception->getMessage(),
            'errorClass' => get_class($exception),
        ],
        JSON_THROW_ON_ERROR
    ) . PHP_EOL;
}
