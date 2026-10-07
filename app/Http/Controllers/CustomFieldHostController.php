<?php

namespace App\Http\Controllers;

use App\Domain\CustomFields\CustomFieldHostService;
use App\Domain\CustomFields\CustomFieldValidationException;
use App\Domain\CustomFields\UserFieldOptions;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * JSON and HTML edits for enumeration and document custom fields, and the
 * user-format option list.
 *
 * A guest is a null actor. These routes do not sign anyone in. Enumeration
 * writes require an active admin. Document writes use the document permissions
 * on the project in the URL.
 */
class CustomFieldHostController extends Controller
{
    public function __construct(
        private readonly CustomFieldHostService $hosts,
        private readonly UserFieldOptions $userOptions,
    ) {}

    public function index(Request $request, string $type): JsonResponse
    {
        try {
            $payload = $this->hosts->index($this->actor($request), $type);
        } catch (PermissionDeniedException $denied) {
            return response()->json(['message' => $denied->getMessage()], 403);
        } catch (DomainException $exception) {
            return $this->missing($exception);
        }

        return response()->json($payload);
    }

    public function editEnumeration(Request $request, Enumeration $enumeration): JsonResponse|View|Response
    {
        try {
            $fields = $this->hosts->readEnumeration($this->actor($request), $enumeration);
        } catch (PermissionDeniedException $denied) {
            return $this->denied($request, $denied);
        } catch (DomainException $exception) {
            return $this->missing($exception);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'enumeration' => [
                    'id' => (int) $enumeration->id,
                    'type' => (string) $enumeration->type,
                    'name' => (string) $enumeration->name,
                ],
                'custom_fields' => $fields,
            ]);
        }

        return view('custom-fields.host', [
            'title' => (string) $enumeration->name,
            'action' => route('enumerations.custom-fields.update', ['enumeration' => $enumeration->id]),
            'method' => 'PUT',
            'fields' => $fields,
        ]);
    }

    public function updateEnumeration(Request $request, Enumeration $enumeration): JsonResponse|Response|RedirectResponse
    {
        return $this->writeEnumeration($request, $enumeration, false);
    }

    public function storeEnumeration(Request $request, Enumeration $enumeration): JsonResponse|Response|RedirectResponse
    {
        return $this->writeEnumeration($request, $enumeration, true);
    }

    public function showDocument(Request $request, Project $project, int $document): JsonResponse|View|Response
    {
        try {
            $fields = $this->hosts->readDocument($this->actor($request), $project, $document);
        } catch (PermissionDeniedException $denied) {
            return $this->denied($request, $denied);
        } catch (DomainException $exception) {
            return $this->missing($exception);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'document_id' => $document,
                'project_id' => (int) $project->id,
                'custom_fields' => $fields,
            ]);
        }

        return view('custom-fields.host', [
            'title' => 'Document '.$document,
            'action' => route('documents.custom-fields.update', ['project' => $project->id, 'document' => $document]),
            'method' => 'PUT',
            'fields' => $fields,
        ]);
    }

    public function updateDocument(Request $request, Project $project, int $document): JsonResponse|Response|RedirectResponse
    {
        return $this->writeDocument($request, $project, $document, false);
    }

    public function storeDocument(Request $request, Project $project, int $document): JsonResponse|Response|RedirectResponse
    {
        return $this->writeDocument($request, $project, $document, true);
    }

    public function users(Request $request, CustomField $customField): JsonResponse
    {
        if ((string) $customField->field_format !== 'user') {
            return response()->json(['message' => 'Custom field is not a user field.'], 404);
        }

        $actor = $this->actor($request);
        $project = $this->project($request);
        if ($request->query('list') === 'filter') {
            $list = $this->userOptions->filterList($actor, $project);

            return response()->json($list);
        }

        $record = $this->editRecord($request, $project);

        return response()->json([
            'ids' => $this->userOptions->offered($actor, $customField, $record),
            'me_label' => $this->userOptions->editMeLabel($actor, $customField, $record),
        ]);
    }

    private function writeEnumeration(Request $request, Enumeration $enumeration, bool $applyDefaults): JsonResponse|Response|RedirectResponse
    {
        try {
            $fields = $this->hosts->writeEnumeration(
                $this->actor($request),
                $enumeration,
                $this->hosts->inputs($request->input('custom_fields')),
                $applyDefaults,
            );
        } catch (PermissionDeniedException $denied) {
            return $this->denied($request, $denied);
        } catch (CustomFieldValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors,
            ], 422);
        } catch (DomainException $exception) {
            return $this->missing($exception);
        }

        if ($request->expectsJson()) {
            return response()->json(['custom_fields' => $fields]);
        }

        return redirect()->route('enumerations.custom-fields.edit', ['enumeration' => $enumeration->id]);
    }

    private function writeDocument(Request $request, Project $project, int $document, bool $applyDefaults): JsonResponse|Response|RedirectResponse
    {
        try {
            $fields = $this->hosts->writeDocument(
                $this->actor($request),
                $project,
                $document,
                $this->hosts->inputs($request->input('custom_fields')),
                $applyDefaults,
            );
        } catch (PermissionDeniedException $denied) {
            return $this->denied($request, $denied);
        } catch (CustomFieldValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors,
            ], 422);
        } catch (DomainException $exception) {
            return $this->missing($exception);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'document_id' => $document,
                'project_id' => (int) $project->id,
                'custom_fields' => $fields,
            ]);
        }

        return redirect()->route('documents.custom-fields.show', ['project' => $project->id, 'document' => $document]);
    }

    private function editRecord(Request $request, ?Project $project): ?Issue
    {
        $issueId = $request->query('issue_id');
        if (! is_numeric($issueId)) {
            if (! $project instanceof Project) {
                return null;
            }
            $issue = new Issue;
            $issue->project_id = $project->id;
            $issue->setRelation('project', $project);

            return $issue;
        }

        $issue = Issue::query()->find((int) $issueId);

        return $issue instanceof Issue ? $issue : null;
    }

    private function project(Request $request): ?Project
    {
        $projectId = $request->query('project_id');
        if (! is_numeric($projectId)) {
            return null;
        }
        $project = Project::query()->find((int) $projectId);

        return $project instanceof Project ? $project : null;
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function denied(Request $request, PermissionDeniedException $denied): JsonResponse|Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $denied->getMessage()], 403);
        }

        return response($denied->getMessage(), 403);
    }

    private function missing(DomainException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage()], 404);
    }
}
