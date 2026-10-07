<?php

namespace Tests\Feature;

use App\Domain\Projects\ProjectService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

/**
 * Calendar, gantt, and project-list routes. The pages are not a Redmine screen.
 */
class CalendarGanttHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_calendar_and_gantt_follow_the_module_and_permission(): void
    {
        $world = DomainFixture::boot();
        $world->join();
        $projects = app(ProjectService::class);

        $this->get('/issues/calendar?year=2026&month=9')->assertForbidden();
        $this->actingAs($world->user)->get('/issues/calendar?year=2026&month=9')->assertForbidden();
        $this->actingAs($world->user)->get('/projects/'.$world->project->id.'/issues/gantt?year=2026&month=8')->assertForbidden();

        $projects->enableModule($world->project, 'calendar');
        $projects->enableModule($world->project, 'gantt');
        $world->role->permissions = [
            'view_issues',
            'add_issues',
            'edit_issues',
            'edit_own_issues',
            'manage_subtasks',
            'add_issue_notes',
            'view_calendar',
            'view_gantt',
        ];
        $world->role->save();

        $this->actingAs($world->user)
            ->get('/issues/calendar?year=2026&month=9')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Calendar/Show')
                ->where('year', 2026)
                ->where('month', 9)
                ->where('projectId', null));

        $this->actingAs($world->user)
            ->get('/projects/'.$world->project->id.'/issues/gantt?year=2026&month=8&months=2&zoom=3')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gantt/Show')
                ->where('png', 'n/a')
                ->where('zoom', 3)
                ->where('projectId', $world->project->id));

        $this->actingAs($world->user)
            ->get('/issues/gantt.pdf?year=2026&month=8')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_project_lists_use_the_query_runners(): void
    {
        $world = DomainFixture::boot();
        $world->join();

        $this->get('/projects')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Index')
                ->has('rows', 1));

        $this->actingAs($world->user)->get('/admin/projects')->assertForbidden();

        $admin = User::factory()->create(['admin' => true]);
        $this->actingAs($admin)
            ->get('/admin/projects')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Admin')
                ->has('rows', 1));
    }
}
