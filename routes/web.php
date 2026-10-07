<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CustomFieldAssetController;
use App\Http\Controllers\FrontendSmokeController;
use Illuminate\Support\Facades\Route;

Route::get('/', FrontendSmokeController::class);

Route::get('/custom-fields/attachments/{attachment}', [CustomFieldAssetController::class, 'download'])
    ->whereNumber('attachment')
    ->name('custom-fields.attachments.download');

Route::get('/custom-fields/links/{customValue}', [CustomFieldAssetController::class, 'show'])
    ->whereNumber('customValue')
    ->name('custom-fields.links.show');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store']);
});

Route::post('/logout', [SessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
