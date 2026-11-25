<?php

declare(strict_types=1);

use App\Http\Controllers\Api\OrderController;
use App\Services\OrderAmountCalculator;
use App\Services\OrderReservationValidator;
use App\Services\OrderSelectionValidator;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Proteger la base de datos real durante las pruebas.
if (
    getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
    getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
) {
    fwrite(STDERR, "Esta prueba requiere Firestore Emulator.\n");
    exit(1);
}

if (count($argv) !== 5) {
    fwrite(
        STDERR,
        "Uso: php OrderConcurrencyWorker.php evento reserva clave usuario\n"
    );
    exit(1);
}

[, $eventId, $reservationId, $key, $userId] = $argv;

try {
    $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';

    $app->make(Kernel::class)->bootstrap();

    $firestore = new FirestoreClient([
        'projectId' => 'demo-bluepass-hu04',
        'transport' => 'rest',
    ]);

    $request = Request::create(
        '/api/events/' . $eventId . '/orders',
        'POST',
        [
            'reservationId' => $reservationId,
            'ticketTypeId' => 'general',
            'quantity' => 2,
        ],
        [],
        [],
        [
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ]
    );

    $request->attributes->set(
        'firebase_uid',
        $userId
    );

    $request->attributes->set(
        'firebase_role',
        'customer'
    );

    $request->attributes->set(
        'firebase_profile',
        [
            'name' => 'Comprador de concurrencia',
            'email' => 'concurrencia@example.com',
            'role' => 'customer',
            'status' => 'active',
        ]
    );

    $response = (new OrderController())->store(
        $request,
        $eventId,
        $firestore,
        new OrderSelectionValidator(),
        new OrderReservationValidator(),
        new OrderAmountCalculator()
    );

    $body = $response->getData(true);

    echo json_encode([
        'httpStatus' => $response->getStatusCode(),
        'orderId' => $body['order']['id'] ?? null,
        'idempotent' => $body['idempotent'] ?? null,
        'message' => $body['message'] ?? null,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Error durante la prueba: ' .
        get_class($exception) . ': ' .
        $exception->getMessage() . PHP_EOL
    );

    exit(1);
}
