<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\Projects\ProjectService;
use App\Models\Attachment;
use App\Models\Enumeration;
use App\Models\News;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class NewsDocumentsFilesHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_pages_follow_module_permissions(): void
    {
        $world = DomainFixture::boot();
        $projects = app(ProjectService::class);
        foreach (['news', 'documents', 'files'] as $module) {
            $projects->enableModule($world->project, $module);
        }
        $permissions = $world->role->permissions;
        $this->assertIsArray($permissions);
        $world->role->permissions = array_values(array_unique([
            ...$permissions,
            'view_news',
            'manage_news',
            'comment_news',
            'view_documents',
            'add_documents',
            'edit_documents',
            'delete_documents',
            'view_files',
            'manage_files',
        ]));
        $world->role->save();
        $world->join();

        Role::query()->create([
            'name' => 'Anonymous',
            'builtin' => 2,
            'assignable' => false,
            'permissions' => ['view_news', 'view_files'],
            'issues_visibility' => 'default',
        ]);
        $category = Enumeration::query()->create([
            'name' => 'User guide',
            'type' => 'DocumentCategory',
            'active' => true,
            'is_default' => true,
            'position' => 1,
        ]);

        $project = $world->project;
        $this->get('/projects/'.$project->id.'/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('News/Index')
                ->where('scope', 'project')
                ->where('projectId', $project->id)
                ->where('canManage', false)
            );

        $user = $world->user;
        $this->actingAs($user)
            ->post('/projects/'.$project->id.'/news', [
                'title' => '   ',
                'summary' => '',
                'description' => '',
            ])
            ->assertSessionHasErrors('news');

        $this->actingAs($user)
            ->post('/projects/'.$project->id.'/news', [
                'title' => 'Hello',
                'summary' => 'Sum',
                'description' => 'Body',
            ])
            ->assertRedirect('/news/1');

        $this->actingAs($user)
            ->get('/news/1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('News/Show')
                ->where('news.title', 'Hello')
                ->where('watching', false)
                ->where('canComment', true)
                ->where('canManage', true)
            );

        $this->actingAs($user)
            ->post('/news/1/comments', ['content' => 'Noted'])
            ->assertRedirect('/news/1');
        $stored = News::query()->find(1);
        $this->assertNotNull($stored);
        $this->assertSame(1, (int) $stored->comments_count);

        $this->actingAs($user)->post('/news/1/watch')->assertRedirect('/news/1');
        $this->actingAs($user)
            ->get('/news/1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('watching', true)
                ->where('watcherIds', [(int) $user->id])
                ->has('comments', 1)
            );

        $this->actingAs($user)
            ->post('/projects/'.$project->id.'/documents', [
                'title' => 'Guide',
                'category_id' => $category->id,
                'description' => '',
            ])
            ->assertRedirect('/projects/'.$project->id.'/documents');
        $this->actingAs($user)
            ->get('/projects/'.$project->id.'/documents?sort_by=title')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Documents/Index')
                ->where('sortBy', 'title')
                ->where('groups.0.key', 'title:G')
                ->where('groups.0.document_ids', [1])
            );

        $this->actingAs($user)
            ->post('/projects/'.$project->id.'/files', [
                'file' => UploadedFile::fake()->createWithContent('notes.txt', 'notes'),
            ])
            ->assertRedirect('/projects/'.$project->id.'/files');
        $attachment = Attachment::query()->where('filename', 'notes.txt')->first();
        $this->assertInstanceOf(Attachment::class, $attachment);
        $this->actingAs($user)
            ->get('/projects/'.$project->id.'/files/'.$attachment->id.'/download')
            ->assertOk();
        $this->assertSame(1, (int) $attachment->fresh()?->downloads);
        $this->actingAs($user)
            ->get('/projects/'.$project->id.'/files/'.$attachment->id.'/download')
            ->assertOk();
        $this->assertSame(2, (int) $attachment->fresh()?->downloads);
        $this->actingAs($user)
            ->get('/projects/'.$project->id.'/files?sort_by=downloads')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Files/Index')
                ->where('sortBy', 'downloads')
                ->where('containers.0.files.0.downloads', 2)
            );

        $outsider = User::factory()->create();
        $this->actingAs($outsider)
            ->get('/projects/'.$project->id.'/documents')
            ->assertForbidden();

        $other = $projects->create([
            'name' => 'Other',
            'identifier' => 'other',
            'is_public' => true,
        ]);
        $projects->enableModule($other, 'documents');
        app(MembershipService::class)->assignRole($other, $user, $world->role->fresh() ?? $world->role);
        $this->actingAs($user)
            ->getJson('/projects/'.$other->id.'/documents/1/custom-fields')
            ->assertForbidden();
    }
}
