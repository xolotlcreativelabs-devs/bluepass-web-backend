<?php

namespace App\Console\Commands;

use App\Services\PurchaseEmailProcessorService;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Console\Command;
use Throwable;

class ProcessPendingPurchaseEmail extends Command
{
    /**
     * Procesar un correo especifico con autorizacion.
     */
    protected $signature = 'bluepass:email-process
        {emailId : Identificador del correo pendiente}
        {--execute : Autorizar el procesamiento}
        {--confirm= : Confirmacion explicita de ejecucion}';

    protected $description =
        'Revisar o procesar un correo de compra en un entorno controlado.';

    /**
     * Ejecutar el comando interno.
     */
    public function handle(
        FirestoreClient $firestore,
        PurchaseEmailProcessorService $processor
    ): int {
        // Mantener este comando limitado al emulador.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->error(
                'Este comando solo esta habilitado para Firestore Emulator.'
            );

            return self::FAILURE;
        }

        $emailId = $this->argument('emailId');

        if (
            !is_string($emailId) ||
            preg_match('/^[a-f0-9]{64}$/D', $emailId) !== 1
        ) {
            $this->error(
                'El identificador del correo no es valido.'
            );

            return self::INVALID;
        }

        try {
            $document = $firestore
                ->collection('purchaseEmailOutbox')
                ->document($emailId);

            $snapshot = $document->snapshot();

            if (!$snapshot->exists()) {
                $this->error(
                    'El correo pendiente no existe.'
                );

                return self::FAILURE;
            }

            $data = $snapshot->data();

            if (
                !is_array($data) ||
                ($data['type'] ?? null) !== 'purchase_confirmation'
            ) {
                $this->error(
                    'El registro no corresponde a una confirmacion de compra.'
                );

                return self::FAILURE;
            }

            $status = $data['status'] ?? null;
            $orderId = $data['orderId'] ?? null;
            $attempts = $data['attempts'] ?? null;

            if (
                !is_string($status) ||
                !is_string($orderId) ||
                !is_int($attempts)
            ) {
                $this->error(
                    'El registro del correo contiene datos invalidos.'
                );

                return self::FAILURE;
            }

            $this->table(
                ['Campo', 'Valor'],
                [
                    ['Correo ID', $emailId],
                    ['Pedido ID', $orderId],
                    ['Estado', $status],
                    ['Intentos', (string) $attempts],
                ]
            );

            // El comportamiento predeterminado es solo consulta.
            if ($this->option('execute') !== true) {
                $this->info(
                    'Modo consulta: no se proceso ni envio ningun correo.'
                );

                return self::SUCCESS;
            }

            if ($status !== 'pending') {
                $this->warn(
                    'Solo se pueden iniciar correos pendientes.'
                );

                return self::FAILURE;
            }

            // Evitar ejecuciones accidentales.
            if (
                $this->option('confirm') !==
                'PROCESS_PURCHASE_EMAIL'
            ) {
                $this->error(
                    'Falta la confirmacion explicita de ejecucion.'
                );

                return self::INVALID;
            }

            // No activar envio con configuracion ausente.
            if (
                config(
                    'bluepass.purchase_emails_enabled',
                    false
                ) !== true
            ) {
                $this->warn(
                    'El procesamiento de correos esta deshabilitado.'
                );

                return self::FAILURE;
            }

            $mailer = config('mail.default');

            if (
                !is_string($mailer) ||
                $mailer === '' ||
                in_array(
                    $mailer,
                    ['log', 'array'],
                    true
                )
            ) {
                $this->warn(
                    'No existe un proveedor de correo habilitado.'
                );

                return self::FAILURE;
            }

            // El servicio vuelve a verificar pedido y boletos.
            $result = $processor->process($emailId);

            $resultStatus = $result['status'] ?? null;

            if ($resultStatus === 'accepted') {
                $this->info(
                    'El transporte acepto la solicitud de correo.'
                );

                return self::SUCCESS;
            }

            $this->warn(
                'El correo no fue aceptado. Estado: ' .
                (
                    is_string($resultStatus)
                        ? $resultStatus
                        : 'desconocido'
                )
            );

            return self::FAILURE;
        } catch (Throwable $exception) {
            // No mostrar errores internos ni datos sensibles.
            $this->error(
                'No fue posible procesar el correo.'
            );

            return self::FAILURE;
        }
    }
}
