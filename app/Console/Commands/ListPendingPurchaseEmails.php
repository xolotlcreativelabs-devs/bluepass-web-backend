<?php

namespace App\Console\Commands;

use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Console\Command;
use Throwable;

class ListPendingPurchaseEmails extends Command
{
    /**
     * Nombre y opciones del comando.
     */
    protected $signature = 'bluepass:emails-pending
        {--limit=20 : Cantidad maxima de registros a consultar}';

    /**
     * Descripcion del comando.
     */
    protected $description =
        'Consultar correos de compra pendientes sin enviarlos.';

    /**
     * Mostrar los correos pendientes del emulador.
     */
    public function handle(FirestoreClient $firestore): int
    {
        // Durante el desarrollo solo se permite el emulador.
        if (
            getenv('FIRESTORE_EMULATOR_HOST') !== '127.0.0.1:8080' ||
            getenv('GOOGLE_CLOUD_PROJECT') !== 'demo-bluepass-hu04'
        ) {
            $this->error(
                'Este comando solo esta habilitado para Firestore Emulator.'
            );

            return self::FAILURE;
        }

        $limitOption = $this->option('limit');

        if (
            !is_string($limitOption) ||
            preg_match('/^[0-9]{1,3}$/D', $limitOption) !== 1
        ) {
            $this->error(
                'El limite debe ser un numero entero.'
            );

            return self::INVALID;
        }

        $limit = (int) $limitOption;

        if ($limit < 1 || $limit > 100) {
            $this->error(
                'El limite debe estar entre 1 y 100.'
            );

            return self::INVALID;
        }

        try {
            $documents = $firestore
                ->collection('purchaseEmailOutbox')
                ->where('status', '=', 'pending')
                ->limit($limit)
                ->documents();

            $rows = [];

            foreach ($documents as $document) {
                if (!$document->exists()) {
                    continue;
                }

                $data = $document->data();

                if (
                    !is_array($data) ||
                    ($data['status'] ?? null) !== 'pending' ||
                    ($data['type'] ?? null) !== 'purchase_confirmation'
                ) {
                    continue;
                }

                $rows[] = [
                    'Correo ID' => $document->id(),
                    'Pedido ID' => is_string(
                        $data['orderId'] ?? null
                    )
                        ? $data['orderId']
                        : 'Invalido',
                    'Estado' => 'pending',
                    'Intentos' => is_int(
                        $data['attempts'] ?? null
                    )
                        ? $data['attempts']
                        : 'Invalido',
                ];
            }

            if ($rows === []) {
                $this->info(
                    'No se encontraron correos pendientes.'
                );

                return self::SUCCESS;
            }

            $this->table(
                [
                    'Correo ID',
                    'Pedido ID',
                    'Estado',
                    'Intentos',
                ],
                $rows
            );

            $this->info(
                'Registros mostrados: ' . count($rows)
            );

            $this->warn(
                'Modo consulta: no se envio ningun correo.'
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error(
                'No fue posible consultar los correos pendientes.'
            );

            return self::FAILURE;
        }
    }
}
