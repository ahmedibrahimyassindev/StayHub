<?php

use App\Http\Controllers\HotelSearchController;
use Illuminate\Support\Facades\Route;

Route::get('/search/health', function () {
    return response()->json([
        'service' => 'search-service',
        'status' => 'ok',
    ]);
});

Route::get('/search/hotels', [HotelSearchController::class, 'hotels']);
