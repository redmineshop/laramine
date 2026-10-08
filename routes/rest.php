<?php

use App\Http\Controllers\Api\RestController;
use Illuminate\Support\Facades\Route;

$format = 'json|xml';
$project = '[A-Za-z0-9][A-Za-z0-9_\-]*';
$kind = 'issue_priorities|time_entry_activities|document_categories';

Route::get('/issues.{format}', [RestController::class, 'issues'])->where('format', $format);
Route::post('/issues.{format}', [RestController::class, 'storeIssue'])->where('format', $format);
Route::get('/issues/{issue}.{format}', [RestController::class, 'showIssue'])->whereNumber('issue')->where('format', $format);
Route::put('/issues/{issue}.{format}', [RestController::class, 'updateIssue'])->whereNumber('issue')->where('format', $format);
Route::delete('/issues/{issue}.{format}', [RestController::class, 'destroyIssue'])->whereNumber('issue')->where('format', $format);
Route::get('/issues/{issue}/relations.{format}', [RestController::class, 'relations'])->whereNumber('issue')->where('format', $format);
Route::post('/issues/{issue}/relations.{format}', [RestController::class, 'storeRelation'])->whereNumber('issue')->where('format', $format);
Route::get('/issues/{issue}/time_entries.{format}', [RestController::class, 'issueTimeEntries'])->whereNumber('issue')->where('format', $format);
Route::get('/issues/{issue}/journals.{format}', [RestController::class, 'journals'])->whereNumber('issue')->where('format', $format);
Route::post('/issues/{issue}/watchers.{format}', [RestController::class, 'addWatcher'])->whereNumber('issue')->where('format', $format);
Route::delete('/issues/{issue}/watchers/{user}.{format}', [RestController::class, 'removeWatcher'])->whereNumber('issue')->whereNumber('user')->where('format', $format);

Route::get('/relations/{relation}.{format}', [RestController::class, 'showRelation'])->whereNumber('relation')->where('format', $format);
Route::delete('/relations/{relation}.{format}', [RestController::class, 'destroyRelation'])->whereNumber('relation')->where('format', $format);

Route::get('/projects.{format}', [RestController::class, 'projects'])->where('format', $format);
Route::post('/projects.{format}', [RestController::class, 'storeProject'])->where('format', $format);
Route::get('/projects/{project}.{format}', [RestController::class, 'showProject'])->where('project', $project)->where('format', $format);
Route::put('/projects/{project}.{format}', [RestController::class, 'updateProject'])->where('project', $project)->where('format', $format);
Route::delete('/projects/{project}.{format}', [RestController::class, 'destroyProject'])->where('project', $project)->where('format', $format);
Route::put('/projects/{project}/archive.{format}', [RestController::class, 'archiveProject'])->where('project', $project)->where('format', $format);
Route::put('/projects/{project}/unarchive.{format}', [RestController::class, 'unarchiveProject'])->where('project', $project)->where('format', $format);
Route::put('/projects/{project}/close.{format}', [RestController::class, 'closeProject'])->where('project', $project)->where('format', $format);
Route::put('/projects/{project}/reopen.{format}', [RestController::class, 'reopenProject'])->where('project', $project)->where('format', $format);

Route::get('/projects/{project}/memberships.{format}', [RestController::class, 'memberships'])->where('project', $project)->where('format', $format);
Route::post('/projects/{project}/memberships.{format}', [RestController::class, 'storeMembership'])->where('project', $project)->where('format', $format);
Route::get('/projects/{project}/versions.{format}', [RestController::class, 'versions'])->where('project', $project)->where('format', $format);
Route::post('/projects/{project}/versions.{format}', [RestController::class, 'storeVersion'])->where('project', $project)->where('format', $format);
Route::get('/projects/{project}/issue_categories.{format}', [RestController::class, 'categories'])->where('project', $project)->where('format', $format);
Route::post('/projects/{project}/issue_categories.{format}', [RestController::class, 'storeCategory'])->where('project', $project)->where('format', $format);
Route::get('/projects/{project}/news.{format}', [RestController::class, 'projectNews'])->where('project', $project)->where('format', $format);
Route::post('/projects/{project}/news.{format}', [RestController::class, 'storeNews'])->where('project', $project)->where('format', $format);
Route::put('/projects/{project}/wiki/{title}.{format}', [RestController::class, 'saveWiki'])->where('project', $project)->where('title', '[^/]+')->where('format', $format);
Route::delete('/projects/{project}/wiki/{title}.{format}', [RestController::class, 'destroyWiki'])->where('project', $project)->where('title', '[^/]+')->where('format', $format);
Route::get('/projects/{project}/boards.{format}', [RestController::class, 'boards'])->where('project', $project)->where('format', $format);
Route::get('/projects/{project}/files.{format}', [RestController::class, 'files'])->where('project', $project)->where('format', $format);
Route::post('/projects/{project}/files.{format}', [RestController::class, 'storeFile'])->where('project', $project)->where('format', $format);
Route::get('/projects/{project}/documents.{format}', [RestController::class, 'documents'])->where('project', $project)->where('format', $format);
Route::post('/projects/{project}/documents.{format}', [RestController::class, 'storeDocument'])->where('project', $project)->where('format', $format);
Route::get('/projects/{project}/time_entries.{format}', [RestController::class, 'projectTimeEntries'])->where('project', $project)->where('format', $format);

Route::get('/memberships/{membership}.{format}', [RestController::class, 'showMembership'])->whereNumber('membership')->where('format', $format);
Route::put('/memberships/{membership}.{format}', [RestController::class, 'updateMembership'])->whereNumber('membership')->where('format', $format);
Route::delete('/memberships/{membership}.{format}', [RestController::class, 'destroyMembership'])->whereNumber('membership')->where('format', $format);

Route::get('/versions/{version}.{format}', [RestController::class, 'showVersion'])->whereNumber('version')->where('format', $format);
Route::put('/versions/{version}.{format}', [RestController::class, 'updateVersion'])->whereNumber('version')->where('format', $format);
Route::delete('/versions/{version}.{format}', [RestController::class, 'destroyVersion'])->whereNumber('version')->where('format', $format);

Route::get('/issue_categories/{category}.{format}', [RestController::class, 'showCategory'])->whereNumber('category')->where('format', $format);
Route::put('/issue_categories/{category}.{format}', [RestController::class, 'updateCategory'])->whereNumber('category')->where('format', $format);
Route::delete('/issue_categories/{category}.{format}', [RestController::class, 'destroyCategory'])->whereNumber('category')->where('format', $format);

Route::get('/documents/{document}.{format}', [RestController::class, 'showDocument'])->whereNumber('document')->where('format', $format);
Route::put('/documents/{document}.{format}', [RestController::class, 'updateDocument'])->whereNumber('document')->where('format', $format);
Route::delete('/documents/{document}.{format}', [RestController::class, 'destroyDocument'])->whereNumber('document')->where('format', $format);

Route::get('/news.{format}', [RestController::class, 'news'])->where('format', $format);
Route::get('/news/{news}.{format}', [RestController::class, 'showNews'])->whereNumber('news')->where('format', $format);
Route::put('/news/{news}.{format}', [RestController::class, 'updateNews'])->whereNumber('news')->where('format', $format);
Route::delete('/news/{news}.{format}', [RestController::class, 'destroyNews'])->whereNumber('news')->where('format', $format);

Route::get('/time_entries.{format}', [RestController::class, 'timeEntries'])->where('format', $format);
Route::post('/time_entries.{format}', [RestController::class, 'storeTimeEntry'])->where('format', $format);
Route::get('/time_entries/{entry}.{format}', [RestController::class, 'showTimeEntry'])->whereNumber('entry')->where('format', $format);
Route::put('/time_entries/{entry}.{format}', [RestController::class, 'updateTimeEntry'])->whereNumber('entry')->where('format', $format);
Route::delete('/time_entries/{entry}.{format}', [RestController::class, 'destroyTimeEntry'])->whereNumber('entry')->where('format', $format);

Route::get('/users.{format}', [RestController::class, 'users'])->where('format', $format);
Route::post('/users.{format}', [RestController::class, 'storeUser'])->where('format', $format);
Route::get('/users/{user}.{format}', [RestController::class, 'showUser'])->where('user', '[0-9]+|current')->where('format', $format);
Route::put('/users/{user}.{format}', [RestController::class, 'updateUser'])->whereNumber('user')->where('format', $format);
Route::delete('/users/{user}.{format}', [RestController::class, 'destroyUser'])->whereNumber('user')->where('format', $format);

Route::get('/groups.{format}', [RestController::class, 'groups'])->where('format', $format);
Route::post('/groups.{format}', [RestController::class, 'storeGroup'])->where('format', $format);
Route::get('/groups/{group}.{format}', [RestController::class, 'showGroup'])->whereNumber('group')->where('format', $format);
Route::put('/groups/{group}.{format}', [RestController::class, 'updateGroup'])->whereNumber('group')->where('format', $format);
Route::delete('/groups/{group}.{format}', [RestController::class, 'destroyGroup'])->whereNumber('group')->where('format', $format);
Route::post('/groups/{group}/users.{format}', [RestController::class, 'addGroupUser'])->whereNumber('group')->where('format', $format);
Route::delete('/groups/{group}/users/{user}.{format}', [RestController::class, 'removeGroupUser'])->whereNumber('group')->whereNumber('user')->where('format', $format);

Route::get('/trackers.{format}', [RestController::class, 'trackers'])->where('format', $format);
Route::get('/trackers/{tracker}.{format}', [RestController::class, 'showTracker'])->whereNumber('tracker')->where('format', $format);
Route::get('/issue_statuses.{format}', [RestController::class, 'statuses'])->where('format', $format);
Route::get('/issue_statuses/{status}.{format}', [RestController::class, 'showStatus'])->whereNumber('status')->where('format', $format);
Route::get('/enumerations/{kind}.{format}', [RestController::class, 'enumerations'])->where('kind', $kind)->where('format', $format);
Route::get('/custom_fields.{format}', [RestController::class, 'customFields'])->where('format', $format);
Route::get('/custom_fields/{field}.{format}', [RestController::class, 'showCustomField'])->whereNumber('field')->where('format', $format);
Route::get('/queries.{format}', [RestController::class, 'queries'])->where('format', $format);
Route::get('/queries/{query}.{format}', [RestController::class, 'showQuery'])->whereNumber('query')->where('format', $format);
Route::get('/roles.{format}', [RestController::class, 'roles'])->where('format', $format);
Route::get('/roles/{role}.{format}', [RestController::class, 'showRole'])->whereNumber('role')->where('format', $format);

Route::post('/uploads.{format}', [RestController::class, 'upload'])->where('format', $format);
Route::get('/attachments/{attachment}.{format}', [RestController::class, 'showAttachment'])->whereNumber('attachment')->where('format', $format);
Route::delete('/attachments/{attachment}.{format}', [RestController::class, 'destroyAttachment'])->whereNumber('attachment')->where('format', $format);

Route::get('/my/account.{format}', [RestController::class, 'account'])->where('format', $format);
Route::put('/my/account.{format}', [RestController::class, 'updateAccount'])->where('format', $format);
Route::get('/search.{format}', [RestController::class, 'search'])->where('format', $format);

Route::get('/boards/{board}.{format}', [RestController::class, 'topics'])->whereNumber('board')->where('format', $format);
Route::post('/boards/{board}/topics.{format}', [RestController::class, 'storeTopic'])->whereNumber('board')->where('format', $format);
Route::get('/messages/{message}.{format}', [RestController::class, 'showMessage'])->whereNumber('message')->where('format', $format);
Route::put('/messages/{message}.{format}', [RestController::class, 'updateMessage'])->whereNumber('message')->where('format', $format);
Route::delete('/messages/{message}.{format}', [RestController::class, 'destroyMessage'])->whereNumber('message')->where('format', $format);
Route::post('/messages/{message}/replies.{format}', [RestController::class, 'replyMessage'])->whereNumber('message')->where('format', $format);

Route::get('/journals/{journal}.{format}', [RestController::class, 'showJournal'])->whereNumber('journal')->where('format', $format);
Route::put('/journals/{journal}.{format}', [RestController::class, 'updateJournal'])->whereNumber('journal')->where('format', $format);
Route::delete('/journals/{journal}.{format}', [RestController::class, 'destroyJournal'])->whereNumber('journal')->where('format', $format);
