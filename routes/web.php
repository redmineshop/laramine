<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AttachmentController;
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
use App\Http\Controllers\CustomFieldHostController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FrontendSmokeController;
use App\Http\Controllers\IssueFeedController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\ProjectFileController;
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

Route::get('/custom-fields/{customField}/users', [CustomFieldHostController::class, 'users'])
    ->whereNumber('customField')
    ->name('custom-fields.users');

Route::get('/enumerations/{type}', [CustomFieldHostController::class, 'index'])
    ->where('type', 'issue_priorities|time_entry_activities|document_categories')
    ->name('enumerations.index');

Route::get('/enumerations/{enumeration}/custom-fields', [CustomFieldHostController::class, 'editEnumeration'])
    ->whereNumber('enumeration')
    ->name('enumerations.custom-fields.edit');

Route::put('/enumerations/{enumeration}/custom-fields', [CustomFieldHostController::class, 'updateEnumeration'])
    ->whereNumber('enumeration')
    ->name('enumerations.custom-fields.update');

Route::post('/enumerations/{enumeration}/custom-fields', [CustomFieldHostController::class, 'storeEnumeration'])
    ->whereNumber('enumeration')
    ->name('enumerations.custom-fields.store');

Route::get('/projects/{project}/documents/{document}/custom-fields', [CustomFieldHostController::class, 'showDocument'])
    ->whereNumber('document')
    ->name('documents.custom-fields.show');

Route::put('/projects/{project}/documents/{document}/custom-fields', [CustomFieldHostController::class, 'updateDocument'])
    ->whereNumber('document')
    ->name('documents.custom-fields.update');

Route::post('/projects/{project}/documents/{document}/custom-fields', [CustomFieldHostController::class, 'storeDocument'])
    ->whereNumber('document')
    ->name('documents.custom-fields.store');

Route::post('/attachments/upload', [AttachmentController::class, 'upload'])->name('attachments.upload');
Route::post('/attachments/claim', [AttachmentController::class, 'claim'])->name('attachments.claim');
Route::get('/attachments/{objectType}/{objectId}/download', [AttachmentController::class, 'downloadAll'])
    ->where('objectType', 'issues|journals|projects|versions|news|documents')
    ->whereNumber('objectId')
    ->name('attachments.download-all');
Route::get('/attachments/{attachment}/thumbnail', [AttachmentController::class, 'thumbnail'])
    ->whereNumber('attachment')
    ->name('attachments.thumbnail');
Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])
    ->whereNumber('attachment')
    ->name('attachments.download');
Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])
    ->whereNumber('attachment')
    ->name('attachments.destroy');

Route::get('/news', [NewsController::class, 'index'])->name('news.index');
Route::get('/news/{news}', [NewsController::class, 'show'])->whereNumber('news')->name('news.show');
Route::put('/news/{news}', [NewsController::class, 'update'])->whereNumber('news')->name('news.update');
Route::delete('/news/{news}', [NewsController::class, 'destroy'])->whereNumber('news')->name('news.destroy');
Route::post('/news/{news}/comments', [NewsController::class, 'storeComment'])->whereNumber('news')->name('news.comments.store');
Route::delete('/news/{news}/comments/{comment}', [NewsController::class, 'destroyComment'])
    ->whereNumber('news')
    ->whereNumber('comment')
    ->name('news.comments.destroy');
Route::post('/news/{news}/watch', [NewsController::class, 'watch'])->whereNumber('news')->name('news.watch');
Route::delete('/news/{news}/watch', [NewsController::class, 'unwatch'])->whereNumber('news')->name('news.unwatch');

Route::get('/projects/{project}/news', [NewsController::class, 'projectIndex'])
    ->whereNumber('project')
    ->name('projects.news.index');
Route::post('/projects/{project}/news', [NewsController::class, 'store'])
    ->whereNumber('project')
    ->name('projects.news.store');

Route::get('/projects/{project}/documents', [DocumentController::class, 'index'])
    ->whereNumber('project')
    ->name('projects.documents.index');
Route::post('/projects/{project}/documents', [DocumentController::class, 'store'])
    ->whereNumber('project')
    ->name('projects.documents.store');
Route::put('/projects/{project}/documents/{document}', [DocumentController::class, 'update'])
    ->whereNumber('project')
    ->whereNumber('document')
    ->name('projects.documents.update');
Route::delete('/projects/{project}/documents/{document}', [DocumentController::class, 'destroy'])
    ->whereNumber('project')
    ->whereNumber('document')
    ->name('projects.documents.destroy');

Route::get('/projects/{project}/files', [ProjectFileController::class, 'index'])
    ->whereNumber('project')
    ->name('projects.files.index');
Route::post('/projects/{project}/files', [ProjectFileController::class, 'store'])
    ->whereNumber('project')
    ->name('projects.files.store');
Route::delete('/projects/{project}/files/{attachment}', [ProjectFileController::class, 'destroy'])
    ->whereNumber('project')
    ->whereNumber('attachment')
    ->name('projects.files.destroy');

Route::get('/users/current.json', RestUserController::class)->name('rest.current-user');
Route::get('/my.atom', FeedController::class)->name('feed.account');
Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');
Route::get('/activity.atom', [ActivityController::class, 'atom'])->name('activity.atom');
Route::get('/issues.atom', IssueFeedController::class)->name('issues.atom');
Route::get('/projects/{identifier}/activity', [ActivityController::class, 'index'])
    ->where('identifier', '[A-Za-z0-9_\-]+')
    ->name('projects.activity.index');
Route::get('/projects/{identifier}/activity.atom', [ActivityController::class, 'atom'])
    ->where('identifier', '[A-Za-z0-9_\-]+')
    ->name('projects.activity.atom');
Route::get('/projects/{identifier}/issues.atom', IssueFeedController::class)
    ->where('identifier', '[A-Za-z0-9_\-]+')
    ->name('projects.issues.atom');
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
