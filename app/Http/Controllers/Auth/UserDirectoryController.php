<?php

namespace App\Http\Controllers\Auth;

use App\Domain\PermissionDeniedException;
use App\Domain\Queries\QueryPayload;
use App\Domain\Queries\QueryType;
use App\Domain\Queries\QueryValidationException;
use App\Domain\Queries\UserQueryRunner;
use App\Http\Controllers\Controller;
use App\Models\Query;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * User directory. Visibility follows `UserVisibility`. A `query_id` runs that saved UserQuery.
 */
class UserDirectoryController extends Controller
{
    public function __construct(
        private readonly UserQueryRunner $runner,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $actor = $actor instanceof User ? $actor : null;
        $filters = [];
        $sort = null;
        $queryId = $request->query('query_id');
        if (is_numeric($queryId)) {
            $saved = Query::query()->find((int) $queryId);
            if ($saved instanceof Query && (string) $saved->type === QueryType::USER && $actor instanceof User) {
                try {
                    $ids = $this->runner->execute($actor, $saved);
                } catch (PermissionDeniedException) {
                    abort(403);
                } catch (QueryValidationException $exception) {
                    abort(422, $exception->getMessage());
                }

                return view('users.index', [
                    'users' => $this->ordered($ids),
                ]);
            }
        }

        $login = $request->query('login');
        if (is_string($login) && $login !== '') {
            $filters['login'] = ['operator' => '~', 'values' => [$login]];
        }
        try {
            $ids = $this->runner->preview($actor, QueryPayload::filters($filters), $sort);
        } catch (QueryValidationException $exception) {
            abort(422, $exception->getMessage());
        }

        return view('users.index', [
            'users' => $this->ordered($ids),
        ]);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function ordered(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $order = array_flip($ids);

        return User::query()->whereIn('id', $ids)->get()->sortBy(
            fn (User $user): int => $order[(int) $user->id] ?? 0,
        )->values();
    }
}
