<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HelpController extends Controller
{
    /**
     * Consultar el contenido publico de ayuda.
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->query() !== []) {
            return response()->json([
                'message' => 'La consulta contiene parametros no permitidos.',
            ], 422);
        }

        return response()->json([
            'message' => 'Contenido de ayuda consultado correctamente.',
            'categories' => [
                [
                    'id' => 'purchases',
                    'title' => 'Compras y pagos',
                    'questions' => [
                        [
                            'id' => 'purchase-history',
                            'question' => '¿Dónde puedo consultar mis compras?',
                            'answer' => 'Inicia sesión y entra a tu historial de compras para consultar tus pedidos.',
                        ],
                        [
                            'id' => 'payment-status',
                            'question' => '¿Cómo puedo consultar el estado de mi compra?',
                            'answer' => 'Consulta el detalle de tu pedido en el historial de compras para revisar su estado.',
                        ],
                        [
                            'id' => 'purchase-receipt',
                            'question' => '¿Dónde encuentro mi comprobante?',
                            'answer' => 'Los pedidos pagados cuentan con una opción para consultar su comprobante de compra.',
                        ],
                    ],
                ],
                [
                    'id' => 'tickets',
                    'title' => 'Boletos',
                    'questions' => [
                        [
                            'id' => 'my-tickets',
                            'question' => '¿Dónde puedo consultar mis boletos?',
                            'answer' => 'Inicia sesión y visita la sección Mis boletos para consultar tus entradas.',
                        ],
                        [
                            'id' => 'ticket-qr',
                            'question' => '¿Cómo puedo consultar el código QR de mi boleto?',
                            'answer' => 'Abre el detalle de un boleto válido para consultar su código QR.',
                        ],
                    ],
                ],
                [
                    'id' => 'account',
                    'title' => 'Mi cuenta',
                    'questions' => [
                        [
                            'id' => 'update-profile',
                            'question' => '¿Puedo actualizar mis datos?',
                            'answer' => 'Puedes consultar tu perfil y modificar los datos que BluePass permite actualizar.',
                        ],
                        [
                            'id' => 'forgot-password',
                            'question' => '¿Qué hago si olvidé mi contraseña?',
                            'answer' => 'Utiliza la opción de recuperación de contraseña disponible en el inicio de sesión.',
                        ],
                    ],
                ],
                [
                    'id' => 'support',
                    'title' => 'Ayuda y soporte',
                    'questions' => [
                        [
                            'id' => 'request-support',
                            'question' => '¿Cómo puedo reportar un problema?',
                            'answer' => 'Puedes enviar una solicitud de soporte indicando la categoría del problema y una descripción de lo ocurrido.',
                        ],
                        [
                            'id' => 'support-order',
                            'question' => '¿Necesito indicar un pedido?',
                            'answer' => 'La referencia del pedido es opcional. Si la incluyes, debe corresponder a una compra de tu cuenta.',
                        ],
                    ],
                ],
            ],
        ], 200);
    }
}