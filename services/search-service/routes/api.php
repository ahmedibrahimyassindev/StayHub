<?php

use Illuminate\Support\Facades\Route;

Route::get('/search/health', function () {
    return response()->json([
        'service' => 'search-service',
        'status' => 'ok',
    ]);
});

