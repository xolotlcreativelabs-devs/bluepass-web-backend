<?php

declare(strict_types=1);

use App\Services\PurchaseNotificationService;
use App\Services\TicketIdGenerator;
use App\Services\TicketInventorySettlement;
use App\Services\TicketIssuanceService;
use App\Services\TicketIssuanceValidator;
use App\Services\TicketQrToken;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Proteger la base de datos real.
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
        "Uso: php TicketIssuanceConcurrencyWorker.php pedido pago\n"
    );
    exit(1);
}

[, $orderId, $paymentReference] = $argv;

try {
    $app = require dirname(__DIR__, 2)
        . '/bootstrap/app.php';

    $app->make(Kernel::class)->bootstrap();

    $firestore = new FirestoreClient([
        'projectId' => 'demo-bluepass-hu04',
        'transport' => 'rest',
    ]);

    // Utilizar la misma conexion del emulador.
    $notificationService = new PurchaseNotificationService(
        $firestore
    );

    // Preparar todos los servicios necesarios para emitir boletos.
    $service = new TicketIssuanceService(
        $firestore,
        new TicketIssuanceValidator(),
        new TicketIdGenerator(),
        new TicketInventorySettlement(),
        new TicketQrToken(),
        $notificationService
    );

    $result = $service->issueForVerifiedPayment(
        $orderId,
        $paymentReference
    );

    echo json_encode([
        'success' => true,
        'orderId' => $result['orderId'],
        'status' => $result['status'],
        'alreadyIssued' => $result['alreadyIssued'],
        'ticketIds' => $result['ticketIds'],
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    // Reportar los conflictos para revisar el resultado final.
    echo json_encode([
        'success' => false,
        'errorClass' => get_class($exception),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
}
