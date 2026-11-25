<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EventAvailabilityController;
use App\Http\Controllers\Api\EventCatalogController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventImageController;
use App\Http\Controllers\Api\EventStatusController;
use App\Http\Controllers\Api\EventTicketManagementController;
use App\Http\Controllers\Api\EventTicketTypeController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\NewsStatusController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RegisterCustomerController;
use App\Http\Controllers\Api\TicketReservationController;
use App\Http\Controllers\Api\TicketReservationReleaseController;
use App\Http\Controllers\Api\UserProfileController;
use Illuminate\Support\Facades\Route;

// Rutas de autenticacion.
Route::middleware('throttle:bluepass-auth')->group(function () {

    // Registrar compradores.
    Route::post('/auth/register', [RegisterCustomerController::class, 'store']);

    // Validar el inicio de sesion.
    Route::post('/auth/session', [AuthController::class, 'session']);

    // Revocar sesiones del usuario.
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Solicitar recuperacion de contrasena.
    Route::post('/auth/password-reset', [PasswordResetController::class, 'store']);
});

// Rutas protegidas del usuario autenticado.
Route::middleware([
    'throttle:bluepass-api',
    'firebase.auth',
])->group(function () {

    // Consultar el perfil propio.
    Route::get('/users/me', [UserProfileController::class, 'show']);

    // Actualizar el nombre del perfil.
    Route::patch('/users/me', [UserProfileController::class, 'update']);

    // Consultar favoritos propios.
    Route::get(
        '/users/me/favorites',
        [FavoriteController::class, 'index']
    );

    // Agregar un evento a favoritos.
    Route::post(
        '/users/me/favorites/{eventId}',
        [FavoriteController::class, 'store']
    );

    // Quitar un evento de favoritos.
    Route::delete(
        '/users/me/favorites/{eventId}',
        [FavoriteController::class, 'destroy']
    );
});

// Rutas publicas del catalogo de eventos.
Route::middleware('throttle:bluepass-api')->group(function () {

    // Consultar eventos publicados.
    Route::get('/events', [EventCatalogController::class, 'index']);

    // Consultar el detalle de un evento publicado.
    Route::get('/events/{id}', [EventCatalogController::class, 'show']);

    // Consultar tipos de boletos activos.
    Route::get(
        '/events/{id}/ticket-types',
        [EventTicketTypeController::class, 'index']
    );

    // Consultar disponibilidad de boletos.
    Route::get(
        '/events/{id}/availability',
        [EventAvailabilityController::class, 'index']
    );
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

    // Publicar o despublicar un evento.
    Route::patch('/events/{id}/status', [EventStatusController::class, 'update']);

    // Crear tipos de boletos para un evento.
    Route::post(
        '/events/{id}/ticket-types',
        [EventTicketManagementController::class, 'store']
    );

    // Consultar imagenes de un evento.
    Route::get('/events/{id}/images', [EventImageController::class, 'index']);

    // Subir o reemplazar la portada.
    Route::post(
        '/events/{id}/images/cover',
        [EventImageController::class, 'uploadCover']
    );

    // Agregar imagenes a la galeria.
    Route::post(
        '/events/{id}/images/gallery',
        [EventImageController::class, 'uploadGallery']
    );

    // Consultar o descargar imagenes.
    Route::get(
        '/events/{id}/images/{imageId}',
        [EventImageController::class, 'show']
    );

    // Eliminar imagenes.
    Route::delete(
        '/events/{id}/images/{imageId}',
        [EventImageController::class, 'destroy']
    );
});

// Rutas protegidas de reservas.
Route::middleware([
    'throttle:bluepass-api',
    'firebase.auth:customer',
])->group(function () {

    // Crear una reserva temporal de boletos.
    Route::post(
        '/events/{eventId}/reservations',
        [TicketReservationController::class, 'store']
    );

    // Cancelar una reserva y liberar sus boletos.
    Route::delete(
        '/events/{eventId}/reservations/{reservationId}',
        [TicketReservationReleaseController::class, 'destroy']
    );
});

// Rutas publicas de noticias.
Route::middleware('throttle:bluepass-api')->group(function () {

    // Consultar noticias publicadas.
    Route::get('/news', [NewsController::class, 'index']);

    // Consultar el detalle de una noticia publicada.
    Route::get('/news/{id}', [NewsController::class, 'show']);
});

// Rutas de noticias exclusivas del administrador.
Route::middleware([
    'throttle:bluepass-api',
    'firebase.auth:admin',
])->group(function () {

    // Registrar noticias como borradores.
    Route::post('/news', [NewsController::class, 'store']);

    // Editar noticias existentes.
    Route::patch('/news/{id}', [NewsController::class, 'update']);

    // Publicar o despublicar una noticia.
    Route::patch(
        '/news/{id}/status',
        [NewsStatusController::class, 'update']
    );
});
