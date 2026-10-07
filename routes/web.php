<?php

use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RecoveryController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CustomFieldAssetController;
use App\Http\Controllers\FrontendSmokeController;
use Illuminate\Support\Facades\Route;

Route::get('/', FrontendSmokeController::class);

Route::get('/custom-fields/attachments/{attachment}', [CustomFieldAssetController::class, 'download'])
    ->whereNumber('attachment')
    ->name('custom-fields.attachments.download');

Route::delete('/custom-fields/attachments/{attachment}', [CustomFieldAssetController::class, 'destroy'])
    ->whereNumber('attachment')
    ->name('custom-fields.attachments.destroy');

Route::get('/custom-fields/links/{customValue}', [CustomFieldAssetController::class, 'show'])
    ->whereNumber('customValue')
    ->name('custom-fields.links.show');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store']);
    Route::get('/account/register', [RegistrationController::class, 'create'])->name('register');
    Route::post('/account/register', [RegistrationController::class, 'store']);
    Route::get('/account/activate', [RegistrationController::class, 'activate'])->name('account.activate');
    Route::post('/account/activation_email', [RegistrationController::class, 'resend'])->name('account.activation-email');
    Route::get('/account/lost_password', [RecoveryController::class, 'create'])->name('password.request');
    Route::post('/account/lost_password', [RecoveryController::class, 'store'])->name('password.email');
    Route::post('/account/lost_password/reset', [RecoveryController::class, 'reset'])->name('password.reset');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/my/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::post('/my/password', [PasswordController::class, 'update'])->name('password.update');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});
