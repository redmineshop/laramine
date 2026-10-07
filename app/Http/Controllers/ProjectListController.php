<?php

namespace App\Http\Controllers;

use App\Domain\PermissionDeniedException;
use App\Domain\Queries\ProjectQueryRunner;
use App\Domain\Queries\QueryType;
use App\Domain\Queries\QueryValidationException;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project list from ProjectQuery and the administrator list from ProjectAdminQuery.
 * These pages are not Redmine screens.
 */
class ProjectListController extends Controller
{
    public function __construct(private readonly ProjectQueryRunner $projects) {}

    public function index(Request $request): Response
    {
        return $this->page($request, QueryType::PROJECT, 'Projects/Index');
    }

    public function admin(Request $request): Response
    {
        return $this->page($request, QueryType::PROJECT_ADMIN, 'Projects/Admin');
    }

    private function page(Request $request, string $type, string $component): Response
    {
        try {
            $result = $this->projects->run(
                $this->actor($request),
                $type,
                ['status' => ['operator' => '=', 'values' => ['1']]],
            );
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (QueryValidationException) {
            abort(422);
        }

        return Inertia::render($component, [
            'columns' => $result['columns'],
            'rows' => $result['rows'],
        ]);
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
