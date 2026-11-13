<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\EmailNotFound;
use Throwable;

class PasswordResetController extends Controller
{
    /**
     * Solicitar la recuperación de contraseña.
     */
    public function store(Request $request, Auth $auth): JsonResponse
    {
        // Validar el correo proporcionado.
        $validator = Validator::make(
            $request->all(),
            [
                'email' => [
                    'required',
                    'string',
                    'email',
                    'max:254',
                ],
            ],
            [
                'email.required' => 'El correo electrónico es obligatorio.',
                'email.string' => 'El correo electrónico debe ser texto.',
                'email.email' => 'Ingresa un correo electrónico válido.',
                'email.max' => 'El correo electrónico es demasiado largo.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos proporcionados no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = trim($validator->validated()['email']);

        try {
            // Firebase envía el enlace de recuperación.
            $auth->sendPasswordResetLink($email);

        } catch (EmailNotFound $e) {
            // No revelar si el correo está registrado.
        } catch (Throwable $e) {
            Log::error('Error en recuperación de acceso BluePass', [
                'exception' => get_class($e),
            ]);
        }

        // Mantener la misma respuesta para proteger las cuentas.
        return response()->json([
            'message' => 'Si existe una cuenta asociada al correo, recibirás instrucciones para recuperar tu acceso.',
        ], 200);
    }
}
