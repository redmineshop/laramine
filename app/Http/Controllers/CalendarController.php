<?php

namespace App\Http\Controllers;

use App\Domain\Calendar\CalendarService;
use App\Domain\PermissionDeniedException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Month grid. This page is not a Redmine screen.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendar) {}

    public function index(Request $request): Response
    {
        return $this->page($request, null);
    }

    public function project(Request $request, Project $project): Response
    {
        return $this->page($request, $project);
    }

    private function page(Request $request, ?Project $project): Response
    {
        try {
            $grid = $this->calendar->month(
                $this->actor($request),
                $project,
                $this->optionalInt($request, 'year'),
                $this->optionalInt($request, 'month'),
            );
        } catch (PermissionDeniedException) {
            abort(403);
        }

        return Inertia::render('Calendar/Show', [
            'projectId' => $project === null ? null : (int) $project->id,
            'year' => $grid['year'],
            'month' => $grid['month'],
            'firstWday' => $grid['first_wday'],
            'days' => $grid['days'],
        ]);
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function optionalInt(Request $request, string $key): ?int
    {
        $value = $request->query($key);
        if (! is_string($value) || preg_match('/^-?\d+$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
