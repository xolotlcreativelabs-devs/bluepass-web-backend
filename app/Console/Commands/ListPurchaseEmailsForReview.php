<?php

namespace App\Console\Commands;

use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Console\Command;
use Throwable;

class ListPurchaseEmailsForReview extends Command
{
    /**
     * Consultar correos que requieren revision manual.
     */
    protected $signature = 'bluepass:emails-review
        {--limit=20 : Cantidad maxima de registros por consultar}';

    protected $description =
        'Consultar correos interrumpidos o inciertos sin reenviarlos.';

    /**
     * Ejecutar la consulta de forma segura.
     */
    public function handle(FirestoreClient $firestore): int
    {
        // Solo utilizar Firestore Emulator.
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
            preg_match('/^[0-9]+$/D', $limitOption) !== 1
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
            $collection = $firestore
                ->collection('purchaseEmailOutbox');

            $rows = [];

            // Consultar estados separados para evitar un
            // indice compuesto adicional en Firestore.
            foreach (['processing', 'uncertain'] as $status) {
                $documents = $collection
                    ->where('status', '=', $status)
                    ->limit($limit)
                    ->documents();

                foreach ($documents as $document) {
                    if (!$document->exists()) {
                        continue;
                    }

                    $data = $document->data();

                    if (
                        !is_array($data) ||
                        ($data['type'] ?? null) !==
                            'purchase_confirmation' ||
                        ($data['status'] ?? null) !== $status
                    ) {
                        continue;
                    }

                    $orderId = $data['orderId'] ?? null;
                    $attempts = $data['attempts'] ?? null;
                    $errorCode = $data['lastErrorCode'] ?? null;

                    $rows[] = [
                        'emailId' => $document->id(),
                        'orderId' => is_string($orderId)
                            ? $orderId
                            : 'invalido',
                        'status' => $status,
                        'attempts' => is_int($attempts)
                            ? (string) $attempts
                            : 'invalido',
                        'errorCode' => is_string($errorCode)
                            ? $errorCode
                            : '-',
                    ];
                }
            }

            if ($rows === []) {
                $this->info(
                    'No se encontraron correos que requieran revision.'
                );

                return self::SUCCESS;
            }

            // Ordenar por estado e identificador.
            usort(
                $rows,
                static function (array $a, array $b): int {
                    $statusComparison = strcmp(
                        $a['status'],
                        $b['status']
                    );

                    if ($statusComparison !== 0) {
                        return $statusComparison;
                    }

                    return strcmp(
                        $a['emailId'],
                        $b['emailId']
                    );
                }
            );

            // Mostrar como maximo el limite solicitado.
            $rows = array_slice($rows, 0, $limit);

            $tableRows = [];

            foreach ($rows as $row) {
                $tableRows[] = [
                    $row['emailId'],
                    $row['orderId'],
                    $row['status'],
                    $row['attempts'],
                    $row['errorCode'],
                ];
            }

            $this->table(
                [
                    'Correo ID',
                    'Pedido ID',
                    'Estado',
                    'Intentos',
                    'Ultimo error',
                ],
                $tableRows
            );

            $this->warn(
                'Estos correos requieren revision manual.'
            );

            $this->info(
                'Consulta finalizada: no se modificaron registros ' .
                'ni se enviaron correos.'
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // No mostrar datos internos de Firebase.
            $this->error(
                'No fue posible consultar los correos.'
            );

            return self::FAILURE;
        }
    }
}
