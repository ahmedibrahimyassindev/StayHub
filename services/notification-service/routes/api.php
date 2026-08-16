<?php

use Illuminate\Support\Facades\Route;

Route::get('/notifications/health', function () {
    return response()->json([
        'service' => 'notification-service',
        'status' => 'ok',
    ]);
});

