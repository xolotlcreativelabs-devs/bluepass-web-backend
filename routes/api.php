<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RegisterCustomerController;
use App\Http\Controllers\Api\UserProfileController;
use Illuminate\Support\Facades\Route;

// Rutas de autenticación.
Route::middleware('throttle:bluepass-auth')->group(function () {

    // Registrar compradores.
    Route::post('/auth/register', [RegisterCustomerController::class, 'store']);

    // Validar el inicio de sesión.
    Route::post('/auth/session', [AuthController::class, 'session']);

    // Revocar sesiones del usuario.
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Solicitar recuperación de contraseña.
    Route::post('/auth/password-reset', [PasswordResetController::class, 'store']);
});

// Rutas protegidas del perfil.
Route::middleware([
    'throttle:bluepass-api',
    'firebase.auth',
])->group(function () {

    // Consultar el perfil propio.
    Route::get('/users/me', [UserProfileController::class, 'show']);

    // Actualizar el nombre del perfil.
    Route::patch('/users/me', [UserProfileController::class, 'update']);
});
