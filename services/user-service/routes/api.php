<?php

use App\Http\Controllers\UserProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/users/health', function () {
    return response()->json([
        'service' => 'user-service',
        'status' => 'ok',
    ]);
});

Route::get('/users/profiles', [UserProfileController::class, 'index']);
Route::post('/users/profiles', [UserProfileController::class, 'store']);
Route::get('/users/profiles/keycloak/{keycloakUserId}', [UserProfileController::class, 'showByKeycloakId']);
Route::get('/users/profiles/{profile}', [UserProfileController::class, 'show']);
Route::put('/users/profiles/{profile}', [UserProfileController::class, 'update']);
