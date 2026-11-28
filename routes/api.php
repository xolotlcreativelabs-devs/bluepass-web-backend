<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeleteAccountController;
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
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderQueryController;
use App\Http\Controllers\Api\OrderReceiptController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PastEventController;
use App\Http\Controllers\Api\PurchaseNotificationController;
use App\Http\Controllers\Api\RegisterCustomerController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ReviewModerationController;
use App\Http\Controllers\Api\SellerReviewController;
use App\Http\Controllers\Api\TicketPrintController;
use App\Http\Controllers\Api\TicketQueryController;
use App\Http\Controllers\Api\TicketReservationController;
use App\Http\Controllers\Api\TicketReservationReleaseController;
use App\Http\Controllers\Api\UserProfileController;
use Illuminate\Support\Facades\Route;

// Rutas de autenticacion.
Route::middleware('throttle:bluepass-auth')->group(function () {
    Route::post(
        '/auth/register',
        [RegisterCustomerController::class, 'store']
    );

    Route::post(
        '/auth/session',
        [AuthController::class, 'session']
    );

    Route::post(
        '/auth/logout',
        [AuthController::class, 'logout']
    );

    Route::post(
        '/auth/password-reset',
        [PasswordResetController::class, 'store']
    );
});

// Rutas protegidas del usuario autenticado.
Route::middleware([
    'throttle:bluepass-api',
    'firebase.auth',
])->group(function () {
    Route::get(
        '/users/me',
        [UserProfileController::class, 'show']
    );

    Route::patch(
        '/users/me',
        [UserProfileController::class, 'update']
    );

    Route::delete(
        '/users/me',
        [DeleteAccountController::class, 'destroy']
    );

    Route::get(
        '/users/me/favorites',
        [FavoriteController::class, 'index']
    );

    Route::post(
        '/users/me/favorites/{eventId}',
        [FavoriteController::class, 'store']
    );

    Route::delete(
        '/users/me/favorites/{eventId}',
        [FavoriteController::class, 'destroy']
    );
});

// Rutas publicas del catalogo y eventos pasados.
Route::middleware('throttle:bluepass-api')->group(function () {
    Route::get(
        '/events',
        [EventCatalogController::class, 'index']
    );

    Route::get(
        '/events/past',
        [PastEventController::class, 'index']
    );

    Route::get(
        '/events/past/{id}',
        [PastEventController::class, 'show']
    );

    Route::get(
        '/events/{id}/reviews',
        [PastEventController::class, 'reviews']
    );

    Route::get(
        '/events/{id}',
        [EventCatalogController::class, 'show']
    );

    Route::get(
        '/events/{id}/ticket-types',
        [EventTicketTypeController::class, 'index']
    );

    Route::get(
        '/events/{id}/availability',
        [EventAvailabilityController::class, 'index']
    );
});

// Rutas protegidas de administracion de eventos.
Route::middleware([
    'throttle:bluepass-api',
    'firebase.auth:seller,admin',
])->group(function () {
    Route::post(
        '/events',
        [EventController::class, 'store']
    );

    Route::patch(
        '/events/{id}',
        [EventController::class, 'update']
    );

    Route::patch(
        '/events/{id}/status',
        [EventStatusController::class, 'update']
    );

    Route::post(
        '/events/{id}/ticket-types',
        [EventTicketManagementController::class, 'store']
    );

    Route::get(
        '/events/{id}/images',
        [EventImageController::class, 'index']
    );

    Route::post(
        '/events/{id}/images/cover',
        [EventImageController::class, 'uploadCover']
    );

    Route::post(
        '/events/{id}/images/gallery',
        [EventImageController::class, 'uploadGallery']
    );

    Route::get(
        '/events/{id}/images/{imageId}',
        [EventImageController::class, 'show']
    );

    Route::delete(
        '/events/{id}/images/{imageId}',
        [EventImageController::class, 'destroy']
    );
});

// Rutas exclusivas de vendedores.
Route::middleware([
    'throttle:bluepass-api',
    'firebase.auth:seller',
])->group(function () {
    Route::get(
        '/seller/events/{eventId}/reviews',
        [SellerReviewController::class, 'index']
    );
});

// Rutas protegidas de reservas, pedidos, boletos y comprobantes.
Route::middleware([
    'throttle:bluepass-api',
    'firebase.auth:customer',
])->group(function () {
    Route::post(
        '/events/{eventId}/reservations',
        [TicketReservationController::class, 'store']
    );

    Route::delete(
        '/events/{eventId}/reservations/{reservationId}',
        [TicketReservationReleaseController::class, 'destroy']
    );

    Route::post(
        '/events/{eventId}/orders',
        [OrderController::class, 'store']
    );

    Route::get(
        '/orders',
        [OrderQueryController::class, 'index']
    );

    Route::get(
        '/orders/{orderId}',
        [OrderQueryController::class, 'show']
    );

    Route::get(
        '/orders/{orderId}/receipt',
        [OrderReceiptController::class, 'show']
    );

    Route::get(
        '/tickets',
        [TicketQueryController::class, 'index']
    );

    Route::get(
        '/tickets/{ticketId}',
        [TicketQueryController::class, 'show']
    );

    Route::get(
        '/tickets/{ticketId}/qr',
        [TicketQueryController::class, 'qr']
    );

    Route::get(
        '/tickets/{ticketId}/print',
        [TicketPrintController::class, 'show']
    );

    // Publicar una resena despues del evento.
    Route::post(
        '/events/{id}/reviews',
        [ReviewController::class, 'store']
    );

    // Editar la resena del comprador autenticado.
    Route::patch(
        '/events/{id}/reviews/me',
        [ReviewController::class, 'update']
    );

    Route::get(
        '/users/me/notifications',
        [PurchaseNotificationController::class, 'index']
    );

    Route::patch(
        '/users/me/notifications/{notificationId}/read',
        [PurchaseNotificationController::class, 'markRead']
    );
});

// Rutas publicas de noticias.
Route::middleware('throttle:bluepass-api')->group(function () {
    Route::get(
        '/news',
        [NewsController::class, 'index']
    );

    Route::get(
        '/news/{id}',
        [NewsController::class, 'show']
    );
});

// Rutas exclusivas del administrador.
Route::middleware([
    'throttle:bluepass-api',
    'firebase.auth:admin',
])->group(function () {
    // Ocultar una resena y registrar la auditoria.
    Route::patch(
        '/admin/events/{eventId}/reviews/{reviewId}/hide',
        [ReviewModerationController::class, 'hide']
    );

    // Crear una noticia.
    Route::post(
        '/news',
        [NewsController::class, 'store']
    );

    // Editar una noticia.
    Route::patch(
        '/news/{id}',
        [NewsController::class, 'update']
    );

    // Publicar o despublicar una noticia.
    Route::patch(
        '/news/{id}/status',
        [NewsStatusController::class, 'update']
    );
});
