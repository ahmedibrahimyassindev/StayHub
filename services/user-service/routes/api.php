<?php

use Illuminate\Support\Facades\Route;

Route::get('/users/health', function () {
    return response()->json([
        'service' => 'user-service',
        'status' => 'ok',
    ]);
});

