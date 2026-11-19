<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventImageController;
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

// Rutas protegidas de eventos.
Route::middleware([
    'throttle:bluepass-api',
    'firebase.auth:seller,admin',
])->group(function () {

    // Registrar eventos como borradores.
    Route::post('/events', [EventController::class, 'store']);

    // Editar eventos existentes.
    Route::patch('/events/{id}', [EventController::class, 'update']);

    // Consultar imágenes de un evento.
    Route::get('/events/{id}/images', [EventImageController::class, 'index']);

    // Subir o reemplazar la portada de un evento.
    Route::post('/events/{id}/images/cover', [EventImageController::class, 'uploadCover']);

    // Agregar imágenes a la galería de un evento.
    Route::post('/events/{id}/images/gallery', [EventImageController::class, 'uploadGallery']);

    // Consultar o descargar una imagen del evento.
    Route::get('/events/{id}/images/{imageId}', [EventImageController::class, 'show']);

    // Eliminar la portada o una imagen de la galería.
    Route::delete('/events/{id}/images/{imageId}', [EventImageController::class, 'destroy']);
});
