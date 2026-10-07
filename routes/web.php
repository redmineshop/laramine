<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\FrontendSmokeController;
use Illuminate\Support\Facades\Route;

Route::get('/', FrontendSmokeController::class);

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store']);
});

Route::post('/logout', [SessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
