<?php

use App\Http\Controllers\RoomInventoryController;
use Illuminate\Support\Facades\Route;

Route::get('/inventory/health', function () {
    return response()->json([
        'service' => 'inventory-service',
        'status' => 'ok',
    ]);
});

Route::get('/inventory', [RoomInventoryController::class, 'index']);
Route::put('/inventory', [RoomInventoryController::class, 'upsert']);
Route::post('/inventory/reservations', [RoomInventoryController::class, 'reserve']);
Route::post('/inventory/releases', [RoomInventoryController::class, 'release']);
