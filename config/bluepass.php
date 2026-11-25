<?php

return [
    /*
     * Porcentaje de cargo por servicio de BluePass.
     * Se aplica al subtotal del pedido.
     * El valor inicial es 0 hasta definir una tarifa.
     */
    'service_fee_percent' => env(
        'BLUEPASS_SERVICE_FEE_PERCENT',
        '0'
    ),
];
