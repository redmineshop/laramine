<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\AccountAdminService;
use App\Domain\Auth\AccountValidationException;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Account administration under `users.admin`. These forms are not Redmine screens.
 */
class UserAdminController extends Controller
{
    public function __construct(
        private readonly AccountAdminService $accounts,
    ) {}

    public function create(Request $request): View
    {
        $this->assertAdmin($request);

        return view('users.form', ['subject' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        try {
            $user = $this->accounts->create($actor, $request->all());
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (AccountValidationException $exception) {
            return back()->withErrors($exception->errors)->withInput();
        }

        return redirect()->route('users.edit', $user);
    }

    public function edit(Request $request, User $user): View
    {
        $this->assertAdmin($request);

        return view('users.form', ['subject' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $actor = $this->actor($request);
        try {
            $this->accounts->update($actor, $user, $request->all());
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (AccountValidationException $exception) {
            return back()->withErrors($exception->errors)->withInput();
        } catch (DomainException $exception) {
            return back()->withErrors(['user' => $exception->getMessage()]);
        }

        return redirect()->route('users.edit', $user);
    }

    public function lock(Request $request, User $user): RedirectResponse
    {
        return $this->transition($request, $user, 'lock');
    }

    public function unlock(Request $request, User $user): RedirectResponse
    {
        return $this->transition($request, $user, 'unlock');
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        return $this->transition($request, $user, 'activate');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $actor = $this->actor($request);
        try {
            $this->accounts->delete($actor, $user);
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['user' => $exception->getMessage()]);
        }

        return redirect()->route('users.index');
    }

    public function addGroup(Request $request, User $user): RedirectResponse
    {
        $actor = $this->actor($request);
        $group = User::query()->find($request->integer('group_id'));
        if (! $group instanceof User) {
            return back()->withErrors(['group_id' => 'Group is unknown.']);
        }
        try {
            $this->accounts->addToGroup($actor, $user, $group);
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['group_id' => $exception->getMessage()]);
        }

        return redirect()->route('users.edit', $user);
    }

    public function removeGroup(Request $request, User $user, User $group): RedirectResponse
    {
        try {
            $this->accounts->removeFromGroup($this->actor($request), $user, $group);
        } catch (PermissionDeniedException) {
            abort(403);
        }

        return redirect()->route('users.edit', $user);
    }

    private function transition(Request $request, User $user, string $method): RedirectResponse
    {
        $actor = $this->actor($request);
        try {
            match ($method) {
                'lock' => $this->accounts->lock($actor, $user),
                'unlock' => $this->accounts->unlock($actor, $user),
                'activate' => $this->accounts->activate($actor, $user),
                default => throw new DomainException('Unknown status transition.'),
            };
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['user' => $exception->getMessage()]);
        }

        return redirect()->route('users.edit', $user);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function assertAdmin(Request $request): void
    {
        $user = $this->actor($request);
        if ($user->admin !== true || ! $user->isActive()) {
            abort(403);
        }
    }
}
