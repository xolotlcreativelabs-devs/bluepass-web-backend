<?php

use App\Mail\PurchaseConfirmationMail;
use App\Services\PurchaseEmailProcessorService;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Mail;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Ejecutar un intento de correo desde un proceso independiente.
 *
 * Este archivo solo permite Firestore Emulator y Mail::fake().
 */

function workerResult(
    string $status,
    int $sentCount,
    ?string $error = null
): void {
    echo json_encode(
        [
            'status' => $status,
            'sentCount' => $sentCount,
            'error' => $error,
        ],
        JSON_THROW_ON_ERROR
    ) . PHP_EOL;
}

if (
    getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
    getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
) {
    workerResult(
        'blocked',
        0,
        'Firestore Emulator es obligatorio.'
    );

    exit(1);
}

$emailId = $argv[1] ?? null;
$startAt = $argv[2] ?? null;

if (
    !is_string($emailId) ||
    preg_match('/^[a-f0-9]{64}$/D', $emailId) !== 1 ||
    !is_string($startAt) ||
    preg_match('/^[0-9]{10,16}$/D', $startAt) !== 1
) {
    workerResult(
        'invalid',
        0,
        'Los argumentos no son validos.'
    );

    exit(1);
}

try {
    // Iniciar Laravel dentro de este proceso PHP.
    $app = require dirname(__DIR__, 2) .
        '/bootstrap/app.php';

    $app->make(Kernel::class)->bootstrap();

    $firestore = new FirestoreClient([
        'projectId' => 'demo-bluepass-hu04',
        'transport' => 'rest',
    ]);

    $app->instance(
        FirestoreClient::class,
        $firestore
    );

    // Nunca conectar un proveedor de correo real.
    Mail::fake();

    config([
        'bluepass.purchase_emails_enabled' => true,
        'mail.default' => 'array',
    ]);

    $processor = new PurchaseEmailProcessorService(
        $firestore
    );

    // Esperar hasta el instante compartido por los procesos.
    $startSeconds = ((int) $startAt) / 1000;

    $remaining = $startSeconds - microtime(true);

    if ($remaining > 0) {
        usleep(
            (int) (min($remaining, 5) * 1000000)
        );
    }

    try {
        $result = $processor->process($emailId);

        $status = $result['status'] ?? 'unknown';

        $sentCount = Mail::sent(
            PurchaseConfirmationMail::class
        )->count();

        workerResult(
            is_string($status) ? $status : 'unknown',
            $sentCount
        );
    } catch (Throwable $exception) {
        // Un conflicto puede terminar uno de los procesos.
        // Se informa para que la prueba compruebe Firestore.
        $sentCount = Mail::sent(
            PurchaseConfirmationMail::class
        )->count();

        workerResult(
            'error',
            $sentCount,
            get_class($exception)
        );
    }
} catch (Throwable $exception) {
    workerResult(
        'bootstrap_error',
        0,
        get_class($exception)
    );

    exit(1);
}
