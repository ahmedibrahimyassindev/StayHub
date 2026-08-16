<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/payments/health', function () {
    return response()->json([
        'service' => 'payment-service',
        'status' => 'ok',
    ]);
});

Route::get('/payments', [PaymentController::class, 'index']);
Route::post('/payments', [PaymentController::class, 'store']);
Route::get('/payments/{payment}', [PaymentController::class, 'show']);
Route::post('/payments/{payment}/succeed', [PaymentController::class, 'succeed']);
Route::post('/payments/{payment}/fail', [PaymentController::class, 'fail']);
Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund']);
