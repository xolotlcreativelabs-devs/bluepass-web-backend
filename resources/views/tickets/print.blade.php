
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Boleto BluePass</title>

    <style>
        :root {
            --navy: #07162f;
            --navy-light: #102747;
            --cyan: #00d4ff;
            --white: #ffffff;
            --muted: #a9bdd2;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 40px 20px;
            background: #e9eef4;
            color: var(--white);
            font-family: Arial, Helvetica, sans-serif;
        }

        .page {
            max-width: 760px;
            margin: 0 auto;
        }

        .actions {
            margin-bottom: 20px;
            text-align: right;
        }

        .print-button {
            padding: 13px 24px;
            border: 0;
            border-radius: 9px;
            background: #087d9f;
            color: var(--white);
            font-weight: bold;
            font-size: 15px;
            cursor: pointer;
        }

        .ticket {
            overflow: hidden;
            border-radius: 20px;
            background: var(--navy);
            box-shadow: 0 15px 40px rgba(0, 0, 0, .18);
        }

        .ticket-header {
            padding: 28px 34px;
            background: var(--navy-light);
            border-bottom: 3px solid var(--cyan);
        }

        .brand {
            font-size: 32px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .brand-blue {
            color: var(--white);
        }

        .brand-pass {
            color: var(--cyan);
        }

        .slogan {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 11px;
            letter-spacing: 2px;
        }

        .ticket-body {
            padding: 32px 34px;
        }

        .eyebrow {
            margin-bottom: 8px;
            color: var(--cyan);
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .event-name {
            margin: 0 0 24px;
            font-size: 28px;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }

        .details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 22px;
        }

        .field-label {
            margin-bottom: 7px;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
        }

        .field-value {
            color: var(--white);
            font-size: 15px;
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        .ticket-bottom {
            display: flex;
            align-items: center;
            gap: 28px;
            padding: 28px 34px;
            border-top: 2px dashed #6b829e;
        }

        .qr-container {
            width: 180px;
            min-width: 180px;
            padding: 12px;
            border-radius: 12px;
            background: #fff;
        }

        .qr-image {
            display: block;
            width: 100%;
            height: auto;
        }

        .qr-info {
            min-width: 0;
            flex: 1;
        }

        .ticket-id {
            margin: 10px 0;
            color: var(--cyan);
            font-size: 13px;
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        .instruction {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .ticket-footer {
            padding: 18px 34px;
            background: var(--navy-light);
            color: var(--muted);
            font-size: 11px;
            text-align: center;
        }

        @media (max-width: 540px) {
            .details {
                grid-template-columns: 1fr;
            }

            .ticket-bottom {
                flex-direction: column;
                align-items: flex-start;
            }

            .ticket-header,
            .ticket-body,
            .ticket-bottom {
                padding-left: 22px;
                padding-right: 22px;
            }
        }

        @page {
            size: A4;
            margin: 12mm;
        }

        @media print {
            body {
                padding: 0;
                background: #fff;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            .page {
                max-width: 100%;
            }

            .actions {
                display: none;
            }

            .ticket {
                box-shadow: none;
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <main class="page">
        <div class="actions">
            <button
                type="button"
                class="print-button"
                onclick="window.print()"
            >
                Imprimir o guardar en PDF
            </button>
        </div>

        <article class="ticket">
            <header class="ticket-header">
                <div class="brand">
                    <span class="brand-blue">Blue</span><span class="brand-pass">Pass</span>
                </div>

                <p class="slogan">
                    TU ENTRADA A GRANDES MOMENTOS
                </p>
            </header>

            <section class="ticket-body">
                <div class="eyebrow">
                    Entrada confirmada
                </div>

                <h1 class="event-name">
                    {{ $eventName }}
                </h1>

                <div class="details">
                    <div>
                        <div class="field-label">Fecha de inicio</div>
                        <div class="field-value">
                            {{ $eventDate ?? 'Por confirmar' }}
                        </div>
                    </div>

                    <div>
                        <div class="field-label">Fecha de finalización</div>
                        <div class="field-value">
                            {{ $eventEndAt ?? 'Por confirmar' }}
                        </div>
                    </div>

                    <div>
                        <div class="field-label">Lugar</div>
                        <div class="field-value">
                            {{ $eventVenue }}
                        </div>
                    </div>

                    <div>
                        <div class="field-label">Zona horaria</div>
                        <div class="field-value">
                            {{ $eventTimezone }}
                        </div>
                    </div>

                    <div>
                        <div class="field-label">Tipo de entrada</div>
                        <div class="field-value">
                            {{ $ticketTypeName }}
                        </div>
                    </div>

                    <div>
                        <div class="field-label">Comprador</div>
                        <div class="field-value">
                            {{ $buyerName }}
                        </div>
                    </div>

                    <div>
                        <div class="field-label">Número de boleto</div>
                        <div class="field-value">
                            {{ $ticketPosition ?? 'Individual' }}
                        </div>
                    </div>

                    <div>
                        <div class="field-label">Estado</div>
                        <div class="field-value">
                            Válido
                        </div>
                    </div>
                </div>
            </section>

            <section class="ticket-bottom">
                <div class="qr-container">
                    <img
                        class="qr-image"
                        src="{{ $qrImage }}"
                        alt="Código QR de acceso"
                        width="156"
                        height="156"
                    >
                </div>

                <div class="qr-info">
                    <div class="eyebrow">
                        Tu código de acceso
                    </div>

                    <div class="field-label">
                        Identificador único
                    </div>

                    <div class="ticket-id">
                        {{ $ticketId }}
                    </div>

                    <p class="instruction">
                        Presenta este código QR al ingresar al evento.
                        No compartas fotografías ni copias del boleto.
                        Cada entrada cuenta con una credencial individual.
                    </p>
                </div>
            </section>

            <footer class="ticket-footer">
                BluePass · Boleto individual de acceso

                <div>
                    Pedido: {{ $orderId }}
                </div>
            </footer>
        </article>
    </main>
</body>
</html>
