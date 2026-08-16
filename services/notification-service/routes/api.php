<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/notifications/health', function () {
    return response()->json([
        'service' => 'notification-service',
        'status' => 'ok',
    ]);
});

Route::get('/notifications', [NotificationController::class, 'index']);
Route::post('/notifications', [NotificationController::class, 'store']);
Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
Route::post('/notifications/{notification}/send', [NotificationController::class, 'send']);
Route::post('/notifications/{notification}/fail', [NotificationController::class, 'fail']);
Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
