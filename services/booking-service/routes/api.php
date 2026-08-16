<?php

use App\Http\Controllers\BookingController;
use Illuminate\Support\Facades\Route;

Route::get('/bookings/health', function () {
    return response()->json([
        'service' => 'booking-service',
        'status' => 'ok',
    ]);
});

Route::get('/bookings', [BookingController::class, 'index']);
Route::post('/bookings', [BookingController::class, 'store']);
Route::get('/bookings/{booking}', [BookingController::class, 'show']);
Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
