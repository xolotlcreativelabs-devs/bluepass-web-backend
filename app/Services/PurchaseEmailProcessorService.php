<?php

namespace App\Services;

use App\Mail\PurchaseConfirmationMail;
use DateTimeImmutable;
use Google\Cloud\Core\Exception\AbortedException;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class PurchaseEmailProcessorService
{
    private const MAX_TRANSACTION_ATTEMPTS = 8;

    public function __construct(
        private FirestoreClient $firestore
    ) {
    }

    /**
     * Procesar un correo pendiente de confirmacion.
     *
     * El envio esta deshabilitado de manera predeterminada.
     */
    public function process(string $emailId): array
    {
        if (
            preg_match(
                '/^[a-f0-9]{64}$/D',
                $emailId
            ) !== 1
        ) {
            throw new RuntimeException(
                'El identificador del correo no es valido.'
            );
        }

        // No enviar correos sin autorizacion explicita.
        if (
            config(
                'bluepass.purchase_emails_enabled',
                false
            ) !== true
        ) {
            return [
                'emailId' => $emailId,
                'status' => 'disabled',
                'message' => 'El envio de correos no esta habilitado.',
            ];
        }

        // El transportador log no entrega mensajes reales.
        $mailer = config('mail.default');

        if (
            !is_string($mailer) ||
            $mailer === '' ||
            $mailer === 'log' ||
            (
                $mailer === 'array' &&
                !app()->environment('testing')
            )
        ) {
            return [
                'emailId' => $emailId,
                'status' => 'disabled',
                'message' => 'No existe un proveedor de correo habilitado.',
            ];
        }

        $document = $this->firestore
            ->collection('purchaseEmailOutbox')
            ->document($emailId);

        $claimId = bin2hex(random_bytes(16));

        // Reservar el correo mediante una transaccion.
        // Un conflicto solo debe reintentar la reserva,
        // nunca el envio del mensaje.
        $claim = $this->runTransactionWithRetry(
            function (Transaction $transaction) use (
                $document,
                $claimId
            ): array {
                $emailSnapshot = $transaction->snapshot(
                    $document
                );

                if (!$emailSnapshot->exists()) {
                    throw new RuntimeException(
                        'El correo pendiente no existe.'
                    );
                }

                $email = $emailSnapshot->data();

                if (!is_array($email)) {
                    throw new RuntimeException(
                        'El registro del correo es invalido.'
                    );
                }

                $status = $email['status'] ?? null;

                // Nunca reenviar un correo previamente
                // aceptado, incierto o en procesamiento.
                if (
                    in_array(
                        $status,
                        ['accepted', 'processing', 'uncertain'],
                        true
                    )
                ) {
                    return [
                        'ready' => false,
                        'status' => $status,
                    ];
                }

                if ($status !== 'pending') {
                    throw new RuntimeException(
                        'El estado del correo no es valido.'
                    );
                }

                if (
                    ($email['type'] ?? null)
                    !== 'purchase_confirmation'
                ) {
                    throw new RuntimeException(
                        'El tipo de correo no es valido.'
                    );
                }

                $orderId = $email['orderId'] ?? null;
                $userId = $email['userId'] ?? null;
                $recipientEmail = $email['recipientEmail'] ?? null;
                $recipientName = $email['recipientName'] ?? null;
                $ticketCount = $email['ticketCount'] ?? null;
                $attempts = $email['attempts'] ?? null;

                if (
                    !$this->validId($orderId) ||
                    !$this->validId($userId) ||
                    !is_string($recipientEmail) ||
                    strlen($recipientEmail) > 254 ||
                    filter_var(
                        $recipientEmail,
                        FILTER_VALIDATE_EMAIL
                    ) === false ||
                    !is_string($recipientName) ||
                    mb_strlen($recipientName) > 150 ||
                    !is_int($ticketCount) ||
                    $ticketCount < 1 ||
                    !is_int($attempts) ||
                    $attempts < 0
                ) {
                    throw new RuntimeException(
                        'Los datos del correo son invalidos.'
                    );
                }

                $orderDocument = $this->firestore
                    ->collection('orders')
                    ->document($orderId);

                $orderSnapshot = $transaction->snapshot(
                    $orderDocument
                );

                if (!$orderSnapshot->exists()) {
                    throw new RuntimeException(
                        'El pedido asociado no existe.'
                    );
                }

                $order = $orderSnapshot->data();

                if (
                    !is_array($order) ||
                    ($order['status'] ?? null) !== 'paid' ||
                    ($order['userId'] ?? null) !== $userId
                ) {
                    throw new RuntimeException(
                        'El pedido no esta confirmado.'
                    );
                }

                $ticketIds = $order['issuedTicketIds'] ?? null;

                if (
                    !is_array($ticketIds) ||
                    count($ticketIds) !== $ticketCount ||
                    $ticketIds === [] ||
                    count(array_unique($ticketIds)) !==
                        count($ticketIds)
                ) {
                    throw new RuntimeException(
                        'El pedido no tiene los boletos esperados.'
                    );
                }

                foreach ($ticketIds as $ticketId) {
                    if (!$this->validId($ticketId)) {
                        throw new RuntimeException(
                            'El pedido contiene boletos invalidos.'
                        );
                    }
                }

                $buyer = $order['buyer'] ?? null;

                if (
                    !is_array($buyer) ||
                    ($buyer['id'] ?? null) !== $userId ||
                    ($buyer['email'] ?? null) !==
                        $recipientEmail
                ) {
                    throw new RuntimeException(
                        'El destinatario no coincide con el pedido.'
                    );
                }

                $now = new Timestamp(
                    new DateTimeImmutable()
                );

                // Solo el proceso que confirme esta
                // transaccion podra enviar el correo.
                $transaction->update(
                    $document,
                    [
                        [
                            'path' => 'status',
                            'value' => 'processing',
                        ],
                        [
                            'path' => 'attempts',
                            'value' => $attempts + 1,
                        ],
                        [
                            'path' => 'processingStartedAt',
                            'value' => $now,
                        ],
                        [
                            'path' => 'updatedAt',
                            'value' => $now,
                        ],
                        [
                            'path' => 'claimId',
                            'value' => $claimId,
                        ],
                    ]
                );

                return [
                    'ready' => true,
                    'status' => 'processing',
                    'recipientEmail' => $recipientEmail,
                    'recipientName' => $recipientName,
                    'orderId' => $orderId,
                    'ticketCount' => $ticketCount,
                ];
            }
        );

        // Otro proceso ya reservo o termino el correo.
        if ($claim['ready'] !== true) {
            return [
                'emailId' => $emailId,
                'status' => $claim['status'],
                'alreadyProcessed' => true,
            ];
        }

        // El correo se envia fuera de la transaccion.
        // Esta operacion NO debe reintentarse
        // automaticamente porque podria duplicarse.
        try {
            Mail::to($claim['recipientEmail'])->send(
                new PurchaseConfirmationMail(
                    $claim['recipientName'],
                    $claim['orderId'],
                    $claim['ticketCount']
                )
            );
        } catch (Throwable $exception) {
            // El proveedor pudo aceptar el mensaje
            // antes de devolver el error.
            $this->finish(
                $document,
                $claimId,
                'uncertain',
                'mail_send_exception'
            );

            return [
                'emailId' => $emailId,
                'status' => 'uncertain',
                'message' => 'El resultado del envio requiere revision.',
            ];
        }

        // Accepted significa aceptado para envio,
        // no necesariamente entregado al comprador.
        $this->finish(
            $document,
            $claimId,
            'accepted',
            null
        );

        return [
            'emailId' => $emailId,
            'status' => 'accepted',
            'message' => 'El correo fue aceptado para envio.',
        ];
    }

    /**
     * Reintentar solamente transacciones canceladas
     * por conflictos temporales de Firestore.
     */
    private function runTransactionWithRetry(
        callable $callback
    ): mixed {
        for (
            $attempt = 1;
            $attempt <= self::MAX_TRANSACTION_ATTEMPTS;
            $attempt++
        ) {
            try {
                return $this->firestore->runTransaction(
                    $callback
                );
            } catch (AbortedException $exception) {
                if (
                    $attempt ===
                    self::MAX_TRANSACTION_ATTEMPTS
                ) {
                    throw $exception;
                }

                // Espera progresiva para evitar que
                // los procesos vuelvan a chocar.
                $delay = min(
                    150000 * (2 ** ($attempt - 1)),
                    2000000
                );

                $jitter = random_int(0, 100000);

                usleep($delay + $jitter);
            }
        }

        throw new RuntimeException(
            'No fue posible completar la transaccion.'
        );
    }

    /**
     * Guardar el resultado sin permitir que otro
     * proceso modifique un intento diferente.
     */
    private function finish(
        DocumentReference $document,
        string $claimId,
        string $status,
        ?string $errorCode
    ): void {
        // Tambien se reintenta esta transaccion,
        // pero nunca se vuelve a enviar el mensaje.
        $this->runTransactionWithRetry(
            function (Transaction $transaction) use (
                $document,
                $claimId,
                $status,
                $errorCode
            ): void {
                $snapshot = $transaction->snapshot(
                    $document
                );

                if (!$snapshot->exists()) {
                    throw new RuntimeException(
                        'El registro del correo ya no existe.'
                    );
                }

                $data = $snapshot->data();

                if (
                    !is_array($data) ||
                    ($data['status'] ?? null) !== 'processing' ||
                    ($data['claimId'] ?? null) !== $claimId
                ) {
                    throw new RuntimeException(
                        'El intento de envio ya no es valido.'
                    );
                }

                $timestamp = new Timestamp(
                    new DateTimeImmutable()
                );

                $updates = [
                    [
                        'path' => 'status',
                        'value' => $status,
                    ],
                    [
                        'path' => 'updatedAt',
                        'value' => $timestamp,
                    ],
                    [
                        'path' => 'lastErrorCode',
                        'value' => $errorCode,
                    ],
                ];

                if ($status === 'accepted') {
                    $updates[] = [
                        'path' => 'acceptedAt',
                        'value' => $timestamp,
                    ];
                }

                $transaction->update(
                    $document,
                    $updates
                );
            }
        );
    }

    /**
     * Validar identificadores de Firestore.
     */
    private function validId(mixed $value): bool
    {
        return is_string($value) &&
            preg_match(
                '/^[A-Za-z0-9_-]{1,128}$/D',
                $value
            ) === 1;
    }
}
