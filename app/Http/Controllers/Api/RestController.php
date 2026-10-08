<?php

namespace App\Http\Controllers\Api;

use App\Domain\Api\CatalogApi;
use App\Domain\Api\DirectoryApi;
use App\Domain\Api\IssueApi;
use App\Domain\Api\ProjectApi;
use App\Domain\Api\RecordApi;
use App\Http\Api\ApiResponder;
use App\Http\Api\ApiResult;
use App\Http\Api\RestGate;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON and XML routes for the Redmine-shaped REST API.
 *
 * `GET /users/current.json` stays on the token probe and is not registered here.
 */
final class RestController extends Controller
{
    public function __construct(
        private readonly RestGate $gate,
        private readonly ApiResponder $responder,
        private readonly IssueApi $issues,
        private readonly ProjectApi $projects,
        private readonly DirectoryApi $directory,
        private readonly CatalogApi $catalog,
        private readonly RecordApi $records,
    ) {}

    public function issues(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->issues->index($actor, $request));
    }

    public function showIssue(Request $request, int $issue): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->issues->show($actor, $issue, $request));
    }

    public function storeIssue(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->issues->store($actor, $request));
    }

    public function updateIssue(Request $request, int $issue): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->issues->update($actor, $issue, $request));
    }

    public function destroyIssue(Request $request, int $issue): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->issues->destroy($actor, $issue));
    }

    public function projects(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->index($actor, $request));
    }

    public function showProject(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->show($actor, $project, $request));
    }

    public function storeProject(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->store($actor, $request));
    }

    public function updateProject(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->update($actor, $project, $request));
    }

    public function destroyProject(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->destroy($actor, $project));
    }

    public function memberships(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->memberships($actor, $project, $request));
    }

    public function storeMembership(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->storeMembership($actor, $project, $request));
    }

    public function showMembership(Request $request, int $membership): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->showMembership($actor, $membership));
    }

    public function updateMembership(Request $request, int $membership): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->updateMembership($actor, $membership, $request));
    }

    public function destroyMembership(Request $request, int $membership): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->destroyMembership($actor, $membership));
    }

    public function versions(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->versions($actor, $project));
    }

    public function storeVersion(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->storeVersion($actor, $project, $request));
    }

    public function showVersion(Request $request, int $version): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->showVersion($actor, $version));
    }

    public function updateVersion(Request $request, int $version): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->updateVersion($actor, $version, $request));
    }

    public function destroyVersion(Request $request, int $version): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->destroyVersion($actor, $version));
    }

    public function categories(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->categories($actor, $project));
    }

    public function storeCategory(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->storeCategory($actor, $project, $request));
    }

    public function showCategory(Request $request, int $category): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->showCategory($actor, $category));
    }

    public function updateCategory(Request $request, int $category): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->updateCategory($actor, $category, $request));
    }

    public function destroyCategory(Request $request, int $category): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->projects->destroyCategory($actor, $category));
    }

    public function users(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->users($actor, $request));
    }

    public function showUser(Request $request, string $user): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->showUser($actor, $user));
    }

    public function storeUser(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->storeUser($actor, $request));
    }

    public function updateUser(Request $request, int $user): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->updateUser($actor, $user, $request));
    }

    public function destroyUser(Request $request, int $user): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->destroyUser($actor, $user));
    }

    public function account(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->account($actor));
    }

    public function updateAccount(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->updateAccount($actor, $request));
    }

    public function groups(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->groups($actor, $request));
    }

    public function showGroup(Request $request, int $group): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->showGroup($actor, $group, $request));
    }

    public function storeGroup(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->storeGroup($actor, $request));
    }

    public function updateGroup(Request $request, int $group): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->updateGroup($actor, $group, $request));
    }

    public function destroyGroup(Request $request, int $group): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->destroyGroup($actor, $group));
    }

    public function addGroupUser(Request $request, int $group): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->addGroupUser($actor, $group, $request));
    }

    public function removeGroupUser(Request $request, int $group, int $user): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->directory->removeGroupUser($actor, $group, $user));
    }

    public function trackers(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->catalog->trackers($request));
    }

    public function showTracker(Request $request, int $tracker): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->catalog->showTracker($tracker));
    }

    public function statuses(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->catalog->statuses($request));
    }

    public function showStatus(Request $request, int $status): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->catalog->showStatus($status));
    }

    public function enumerations(Request $request, string $kind): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->catalog->enumerations($kind));
    }

    public function customFields(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->catalog->customFields($actor, $request));
    }

    public function showCustomField(Request $request, int $field): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->catalog->showCustomField($actor, $field));
    }

    public function queries(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->catalog->queries($actor, $request));
    }

    public function showQuery(Request $request, int $query): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->catalog->showQuery($actor, $query));
    }

    public function roles(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->catalog->roles($request));
    }

    public function showRole(Request $request, int $role): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->catalog->showRole($role));
    }

    public function timeEntries(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->timeEntries($actor, $request));
    }

    public function issueTimeEntries(Request $request, int $issue): Response
    {
        $request->query->set('issue_id', (string) $issue);

        return $this->respond($request, fn (User $actor): ApiResult => $this->records->timeEntries($actor, $request));
    }

    public function projectTimeEntries(Request $request, string $project): Response
    {
        $request->query->set('project_id', $project);

        return $this->respond($request, fn (User $actor): ApiResult => $this->records->timeEntries($actor, $request));
    }

    public function storeTimeEntry(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->storeTimeEntry($actor, $request));
    }

    public function showTimeEntry(Request $request, int $entry): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->showTimeEntry($actor, $entry));
    }

    public function updateTimeEntry(Request $request, int $entry): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->updateTimeEntry($actor, $entry, $request));
    }

    public function destroyTimeEntry(Request $request, int $entry): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->destroyTimeEntry($actor, $entry));
    }

    public function relations(Request $request, int $issue): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->relations($actor, $issue));
    }

    public function storeRelation(Request $request, int $issue): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->storeRelation($actor, $issue, $request));
    }

    public function showRelation(Request $request, int $relation): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->showRelation($actor, $relation));
    }

    public function destroyRelation(Request $request, int $relation): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->destroyRelation($actor, $relation));
    }

    public function news(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->newsIndex($actor, $request, null));
    }

    public function projectNews(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->newsIndex($actor, $request, $project));
    }

    public function storeNews(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->storeNews($actor, $project, $request));
    }

    public function showNews(Request $request, int $news): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->showNews($actor, $news));
    }

    public function updateNews(Request $request, int $news): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->updateNews($actor, $news, $request));
    }

    public function destroyNews(Request $request, int $news): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->destroyNews($actor, $news));
    }

    public function wikiIndex(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->wikiIndex($actor, $project));
    }

    public function showWiki(Request $request, string $project, string $title): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->showWiki($actor, $project, $title));
    }

    public function saveWiki(Request $request, string $project, string $title): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->saveWiki($actor, $project, $title, $request));
    }

    public function destroyWiki(Request $request, string $project, string $title): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->destroyWiki($actor, $project, $title));
    }

    public function boards(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->boards($actor, $project));
    }

    public function topics(Request $request, int $board): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->topics($actor, $board));
    }

    public function storeTopic(Request $request, int $board): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->storeTopic($actor, $board, $request));
    }

    public function showMessage(Request $request, int $message): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->showMessage($actor, $message));
    }

    public function replyMessage(Request $request, int $message): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->replyMessage($actor, $message, $request));
    }

    public function updateMessage(Request $request, int $message): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->updateMessage($actor, $message, $request));
    }

    public function destroyMessage(Request $request, int $message): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->destroyMessage($actor, $message));
    }

    public function upload(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->upload($actor, $request));
    }

    public function showAttachment(Request $request, int $attachment): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->showAttachment($actor, $attachment));
    }

    public function destroyAttachment(Request $request, int $attachment): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->destroyAttachment($actor, $attachment));
    }

    public function files(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->files($actor, $project));
    }

    public function storeFile(Request $request, string $project): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->storeFile($actor, $project, $request));
    }

    public function search(Request $request): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->search($actor, $request));
    }

    public function journals(Request $request, int $issue): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->journals($actor, $issue));
    }

    public function showJournal(Request $request, int $journal): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->showJournal($actor, $journal));
    }

    public function updateJournal(Request $request, int $journal): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->updateJournal($actor, $journal, $request));
    }

    public function destroyJournal(Request $request, int $journal): Response
    {
        return $this->respond($request, fn (User $actor): ApiResult => $this->records->destroyJournal($actor, $journal));
    }

    /**
     * @param  callable(User): ApiResult  $action
     */
    private function respond(Request $request, callable $action): Response
    {
        $actor = $this->gate->user($request);
        if (! $actor instanceof User) {
            return $actor;
        }

        return $this->responder->send($this->gate->format($request), $action($actor));
    }
}
