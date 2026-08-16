<?php

use App\Http\Controllers\HotelController;
use Illuminate\Support\Facades\Route;

Route::get('/hotels/health', function () {
    return response()->json([
        'service' => 'hotel-service',
        'status' => 'ok',
    ]);
});

Route::apiResource('hotels', HotelController::class);
