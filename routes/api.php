<?php

use Illuminate\Support\Facades\Route;

// Rutas públicas
Route::post('/login', [\App\Http\Controllers\Api\AuthController::class, 'login']);

// Rutas protegidas
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [\App\Http\Controllers\Api\AuthController::class, 'logout']);
    Route::get('/me', [\App\Http\Controllers\Api\AuthController::class, 'me']);
});