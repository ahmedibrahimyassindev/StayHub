<?php

use Illuminate\Support\Facades\Route;

Route::get('/inventory/health', function () {
    return response()->json([
        'service' => 'inventory-service',
        'status' => 'ok',
    ]);
});

