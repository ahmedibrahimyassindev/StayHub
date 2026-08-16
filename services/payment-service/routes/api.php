<?php

use Illuminate\Support\Facades\Route;

Route::get('/payments/health', function () {
    return response()->json([
        'service' => 'payment-service',
        'status' => 'ok',
    ]);
});

