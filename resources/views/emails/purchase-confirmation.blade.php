<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Confirmacion de compra - BluePass</title>
</head>

<body style="
    margin: 0;
    padding: 30px 15px;
    background-color: #f1f5f9;
    font-family: Arial, sans-serif;
    color: #152238;
">
    <div style="
        max-width: 560px;
        margin: 0 auto;
        background-color: #ffffff;
        border-radius: 12px;
        overflow: hidden;
    ">
        <div style="
            background-color: #071a33;
            padding: 32px 25px;
            text-align: center;
        ">
            <h1 style="
                margin: 0;
                color: #ffffff;
                font-size: 32px;
            ">
                Blue<span style="color: #00c8f8;">Pass</span>
            </h1>

            <p style="
                color: #d8e7f4;
                font-size: 12px;
                letter-spacing: 1px;
                margin-bottom: 0;
            ">
                TU ENTRADA A GRANDES MOMENTOS
            </p>
        </div>

        <div style="padding: 32px 25px;">
            <h2 style="
                color: #071a33;
                margin-top: 0;
            ">
                ¡Tu compra fue confirmada!
            </h2>

            <p>
                Hola, {{ $buyerName }}.
            </p>

            <p style="line-height: 1.7;">
                Tu compra en BluePass se ha confirmado
                correctamente y tus boletos ya estan
                disponibles.
            </p>

            <div style="
                background-color: #f1f8fc;
                padding: 20px;
                border-radius: 8px;
                margin: 24px 0;
            ">
                <p style="margin-top: 0;">
                    <strong>Numero de pedido:</strong>
                    {{ $orderId }}
                </p>

                <p style="margin-bottom: 0;">
                    <strong>Boletos adquiridos:</strong>
                    {{ $ticketCount }}
                </p>
            </div>

            <p style="line-height: 1.7;">
                Ingresa a tu cuenta de BluePass y
                visita la seccion
                <strong>Mis boletos</strong>
                para consultar tus entradas y
                sus codigos QR.
            </p>

            <p style="line-height: 1.7;">
                Conserva este mensaje como referencia
                de tu compra.
            </p>

            <p style="margin-top: 30px;">
                ¡Gracias por elegir BluePass!
            </p>
        </div>

        <div style="
            background-color: #071a33;
            color: #ffffff;
            text-align: center;
            padding: 20px;
            font-size: 12px;
        ">
            BluePass - Tu entrada a grandes momentos.
        </div>
    </div>
</body>
</html>
