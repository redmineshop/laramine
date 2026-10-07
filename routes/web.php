<?php

use App\Http\Controllers\Auth\FeedController;
use App\Http\Controllers\Auth\MyAccountController;
use App\Http\Controllers\Auth\OauthController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RecoveryController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\RestUserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\UserAdminController;
use App\Http\Controllers\Auth\UserDirectoryController;
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

Route::get('/users/current.json', RestUserController::class)->name('rest.current-user');
Route::get('/my.atom', FeedController::class)->name('feed.account');
Route::get('/users', [UserDirectoryController::class, 'index'])->name('users.index');

Route::get('/account/twofa', [TwoFactorController::class, 'challenge'])->name('twofa.challenge');
Route::post('/account/twofa', [TwoFactorController::class, 'verify'])->name('twofa.verify');
Route::post('/account/twofa/backup', [TwoFactorController::class, 'backup'])->name('twofa.backup');

Route::post('/oauth/token', [OauthController::class, 'token'])->name('oauth.token');
Route::post('/oauth/revoke', [OauthController::class, 'revoke'])->name('oauth.revoke');

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

    Route::get('/my/account', [MyAccountController::class, 'edit'])->name('account.edit');
    Route::post('/my/account', [MyAccountController::class, 'update'])->name('account.update');
    Route::post('/my/api_key', [MyAccountController::class, 'apiKey'])->name('account.api-key');
    Route::post('/my/atom_key', [MyAccountController::class, 'atomKey'])->name('account.atom-key');
    Route::get('/my/twofa', [TwoFactorController::class, 'edit'])->name('twofa.edit');
    Route::post('/my/twofa', [TwoFactorController::class, 'confirm'])->name('twofa.confirm');
    Route::delete('/my/twofa', [TwoFactorController::class, 'destroy'])->name('twofa.destroy');

    Route::get('/users/new', [UserAdminController::class, 'create'])->name('users.create');
    Route::post('/users', [UserAdminController::class, 'store'])->name('users.store');
    Route::get('/users/{user}', [UserAdminController::class, 'edit'])->whereNumber('user')->name('users.edit');
    Route::put('/users/{user}', [UserAdminController::class, 'update'])->whereNumber('user')->name('users.update');
    Route::delete('/users/{user}', [UserAdminController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
    Route::post('/users/{user}/lock', [UserAdminController::class, 'lock'])->whereNumber('user')->name('users.lock');
    Route::post('/users/{user}/unlock', [UserAdminController::class, 'unlock'])->whereNumber('user')->name('users.unlock');
    Route::post('/users/{user}/activate', [UserAdminController::class, 'activate'])->whereNumber('user')->name('users.activate');
    Route::post('/users/{user}/groups', [UserAdminController::class, 'addGroup'])->whereNumber('user')->name('users.groups.store');
    Route::delete('/users/{user}/groups/{group}', [UserAdminController::class, 'removeGroup'])->whereNumber('user')->whereNumber('group')->name('users.groups.destroy');

    Route::get('/oauth/authorize', [OauthController::class, 'authorizeForm'])->name('oauth.authorize');
    Route::post('/oauth/authorize', [OauthController::class, 'approve'])->name('oauth.approve');
    Route::post('/oauth/applications', [OauthController::class, 'storeApplication'])->name('oauth.applications.store');
});
