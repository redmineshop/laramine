<?php

namespace Tests\Feature;

use App\Domain\Projects\ProjectService;
use App\Domain\TreeException;
use App\Models\Project;
use App\Models\Tracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_and_move_keep_nested_set_integrity(): void
    {
        $projects = app(ProjectService::class);
        $alpha = $projects->create(['name' => 'Alpha', 'identifier' => 'alpha']);
        $beta = $projects->create(['name' => 'Beta', 'identifier' => 'beta'], $alpha);
        $gamma = $projects->create(['name' => 'Gamma', 'identifier' => 'gamma'], $beta);
        $delta = $projects->create(['name' => 'Delta', 'identifier' => 'delta']);

        $this->assertSame(1, $alpha->fresh()->lft);
        $this->assertSame(6, $alpha->fresh()->rgt);
        $this->assertSame(2, $beta->fresh()->lft);
        $this->assertSame(5, $beta->fresh()->rgt);
        $this->assertSame(3, $gamma->fresh()->lft);
        $this->assertSame(4, $gamma->fresh()->rgt);
        $this->assertSame($alpha->id, $beta->fresh()->parent_id);
        $this->assertNull($delta->fresh()->parent_id);
        $projects->assertConsistent();

        $projects->move($beta->fresh(), $delta->fresh());
        $projects->assertConsistent();
        $beta->refresh();
        $gamma->refresh();
        $delta->refresh();
        $alpha->refresh();
        $this->assertSame($delta->id, $beta->parent_id);
        $this->assertSame($beta->id, $gamma->parent_id);
        $this->assertGreaterThan($delta->lft, $beta->lft);
        $this->assertLessThan($delta->rgt, $beta->rgt);
        $this->assertGreaterThan($beta->lft, $gamma->lft);
        $this->assertLessThan($beta->rgt, $gamma->rgt);
        $this->assertSame(1, $alpha->lft);
        $this->assertSame(2, $alpha->rgt);

        $projects->move($beta->fresh(), null);
        $projects->assertConsistent();
        $this->assertNull($beta->fresh()->parent_id);
        $this->assertSame($beta->id, $gamma->fresh()->parent_id);
    }

    public function test_move_under_descendant_rolls_back(): void
    {
        $projects = app(ProjectService::class);
        $root = $projects->create(['name' => 'Root', 'identifier' => 'root']);
        $child = $projects->create(['name' => 'Child', 'identifier' => 'child'], $root);
        $before = $this->coordinates();

        try {
            $projects->move($root->fresh(), $child->fresh());
            $this->fail('Moving a project under its descendant should fail.');
        } catch (TreeException) {
            $projects->assertConsistent();
        }

        $this->assertSame($before, $this->coordinates());
    }

    public function test_modules_and_trackers(): void
    {
        $projects = app(ProjectService::class);
        $project = $projects->create(['name' => 'Mods', 'identifier' => 'mods']);
        $tracker = Tracker::query()->create(['name' => 'Bug']);

        $this->assertFalse($projects->moduleEnabled($project, 'issue_tracking'));
        $projects->enableModule($project, 'issue_tracking');
        $this->assertTrue($project->fresh()->isModuleEnabled('issue_tracking'));
        $projects->enableModule($project, 'issue_tracking');
        $this->assertSame(1, $project->enabledModules()->count());
        $projects->disableModule($project, 'issue_tracking');
        $this->assertFalse($projects->moduleEnabled($project->fresh(), 'issue_tracking'));

        $this->assertFalse($projects->hasTracker($project, $tracker));
        $projects->attachTracker($project, $tracker);
        $this->assertTrue($projects->hasTracker($project->fresh(), $tracker));
        $projects->detachTracker($project, $tracker);
        $this->assertFalse($projects->hasTracker($project->fresh(), $tracker));
    }

    public function test_random_moves_stay_consistent(): void
    {
        mt_srand(434);
        $projects = app(ProjectService::class);
        $nodes = [$projects->create(['name' => 'P0', 'identifier' => 'p0'])];
        for ($i = 1; $i <= 8; $i++) {
            $parent = (mt_rand(0, 1) === 1) ? $nodes[mt_rand(0, count($nodes) - 1)] : null;
            $nodes[] = $projects->create([
                'name' => 'P'.$i,
                'identifier' => 'p'.$i,
            ], $parent);
        }

        mt_srand(434);
        for ($step = 0; $step < 20; $step++) {
            $node = $nodes[mt_rand(0, count($nodes) - 1)];
            $target = (mt_rand(0, 3) === 0) ? null : $nodes[mt_rand(0, count($nodes) - 1)];
            try {
                $projects->move($node->fresh(), $target?->fresh());
            } catch (TreeException) {
                // Invalid targets are rejected and rolled back.
            }
            $projects->assertConsistent();
        }

        $this->assertSame(9, Project::query()->count());
    }

    /**
     * @return list<array{id: int, parent_id: int|null, lft: int, rgt: int}>
     */
    private function coordinates(): array
    {
        $rows = [];
        foreach (Project::query()->orderBy('id')->get(['id', 'parent_id', 'lft', 'rgt']) as $project) {
            $rows[] = [
                'id' => (int) $project->id,
                'parent_id' => $project->parent_id !== null ? (int) $project->parent_id : null,
                'lft' => (int) $project->lft,
                'rgt' => (int) $project->rgt,
            ];
        }

        return $rows;
    }
}
