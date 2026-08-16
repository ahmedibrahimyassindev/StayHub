<?php

use Illuminate\Support\Facades\Route;

Route::get('/bookings/health', function () {
    return response()->json([
        'service' => 'booking-service',
        'status' => 'ok',
    ]);
});

