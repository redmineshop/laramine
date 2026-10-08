<?php

namespace Tests\Feature;

use App\Domain\Auth\ActionToken;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\SettingValue;
use App\Domain\Wiki\WikiVersionConflictException;
use App\Models\Board;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class RestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_gate_errors_and_key_as_username(): void
    {
        $fixture = DomainFixture::boot();
        $fixture->join();
        $member = $fixture->user;
        $admin = User::factory()->create(['admin' => true, 'login' => 'rest-admin']);
        $passwordUser = User::factory()->withPassword('secret')->create(['login' => 'rest-ada']);
        $fixture->join($passwordUser);

        $memberKey = app(ActionToken::class)->issueNamed($member, Token::ACTION_API)->value;
        $adminKey = app(ActionToken::class)->issueNamed($admin, Token::ACTION_API)->value;

        $this->getJson('/issues.json')->assertUnauthorized()->assertExactJson(['errors' => ['Unauthorized']]);

        $this->setting(SettingValue::REST_API_ENABLED, '1');

        $this->withHeader('X-Redmine-API-Key', $memberKey)
            ->getJson('/issues.json')
            ->assertOk()
            ->assertJsonPath('total_count', 0)
            ->assertJsonPath('offset', 0)
            ->assertJsonPath('limit', 25);

        $this->withHeader('X-Redmine-API-Key', $memberKey)
            ->getJson('/issues.json?nometa=1')
            ->assertOk()
            ->assertJsonMissingPath('total_count');

        $this->withHeader('X-Redmine-API-Key', $memberKey)
            ->get('/trackers.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=utf-8');

        $this->flushHeaders()
            ->withHeader('Authorization', 'Basic '.base64_encode($memberKey.':'))
            ->getJson('/issues.json')
            ->assertOk();

        $this->flushHeaders()
            ->withHeader('Authorization', 'Basic '.base64_encode('rest-ada:secret'))
            ->getJson('/my/account.json')
            ->assertOk()
            ->assertJsonPath('user.login', 'rest-ada');

        $this->flushHeaders()
            ->withHeader('X-Redmine-API-Key', $memberKey)
            ->getJson('/users.json')
            ->assertForbidden()
            ->assertExactJson(['errors' => ['You are not authorized to access this page.']]);

        $this->withHeader('X-Redmine-API-Key', $memberKey)
            ->getJson('/issues/99999.json')
            ->assertNotFound()
            ->assertExactJson(['errors' => ['Not found']]);

        $this->withHeader('X-Redmine-API-Key', $memberKey)
            ->postJson('/issues.json', ['issue' => [
                'project_id' => $fixture->project->id,
                'tracker_id' => $fixture->tracker->id,
                'subject' => '   ',
            ]])
            ->assertStatus(422)
            ->assertExactJson(['errors' => ['Subject is required.']]);

        $this->flushHeaders()
            ->withHeader('X-Redmine-API-Key', $memberKey)
            ->withHeader('X-Redmine-Switch-User', 'rest-ada')
            ->getJson('/my/account.json')
            ->assertStatus(412)
            ->assertExactJson(['error' => 'User impersonation failed']);

        $this->flushHeaders()
            ->withHeader('X-Redmine-API-Key', $adminKey)
            ->withHeader('X-Redmine-Switch-User', 'rest-ada')
            ->getJson('/my/account.json')
            ->assertOk()
            ->assertJsonPath('user.login', 'rest-ada');

        $this->setting(SettingValue::REST_API_ENABLED, '0');
        $this->flushHeaders()
            ->withHeader('X-Redmine-API-Key', $memberKey)
            ->getJson('/issues.json')
            ->assertUnauthorized();
    }

    public function test_wiki_news_and_messages_reuse_module_acl(): void
    {
        $fixture = DomainFixture::boot();
        $fixture->join();
        $member = $fixture->user;
        $admin = User::factory()->create(['admin' => true, 'login' => 'acl-admin']);
        $projects = app(ProjectService::class);
        $projects->enableModule($fixture->project, 'wiki');
        $projects->enableModule($fixture->project, 'news');
        $projects->enableModule($fixture->project, 'boards');
        $fixture->role->permissions = [
            'view_issues',
            'add_issues',
            'view_wiki_pages',
            'view_news',
            'view_messages',
            'add_messages',
            'edit_own_messages',
            'delete_own_messages',
        ];
        $fixture->role->save();
        $this->setting(SettingValue::REST_API_ENABLED, '1');

        $memberKey = app(ActionToken::class)->issueNamed($member, Token::ACTION_API)->value;
        $adminKey = app(ActionToken::class)->issueNamed($admin, Token::ACTION_API)->value;
        $project = $fixture->project->identifier;
        $denied = ['errors' => ['You are not authorized to access this page.']];

        $this->getJson('/projects/'.$project.'/wiki/index.json')->assertUnauthorized();
        $this->getJson('/projects/'.$project.'/boards.json')->assertUnauthorized();

        $this->withHeader('X-Redmine-API-Key', $memberKey)
            ->getJson('/projects/'.$project.'/wiki/Missing.json')
            ->assertNotFound()
            ->assertExactJson(['errors' => ['Not found']]);

        $this->withHeader('X-Redmine-API-Key', $memberKey)
            ->putJson('/projects/'.$project.'/wiki/Guide.json', ['wiki_page' => ['text' => 'member']])
            ->assertForbidden()
            ->assertExactJson($denied);

        $this->withHeader('X-Redmine-API-Key', $memberKey)
            ->postJson('/projects/'.$project.'/news.json', ['news' => ['title' => 'Note']])
            ->assertForbidden()
            ->assertExactJson($denied);

        $this->flushHeaders()
            ->withHeader('X-Redmine-API-Key', $adminKey)
            ->putJson('/projects/'.$project.'/wiki/Guide.json', ['wiki_page' => ['text' => 'first']])
            ->assertOk()
            ->assertJsonPath('wiki_page.version', 1);

        $this->withHeader('X-Redmine-API-Key', $adminKey)
            ->putJson('/projects/'.$project.'/wiki/Guide.json', ['wiki_page' => ['text' => 'second']])
            ->assertOk()
            ->assertJsonPath('wiki_page.version', 2);

        $this->withHeader('X-Redmine-API-Key', $adminKey)
            ->putJson('/projects/'.$project.'/wiki/Guide.json', ['wiki_page' => ['text' => 'stale', 'version' => 1]])
            ->assertStatus(409)
            ->assertExactJson(['errors' => [WikiVersionConflictException::MESSAGE]]);

        $fixture->project->status = Project::STATUS_CLOSED;
        $fixture->project->save();

        $this->flushHeaders()
            ->withHeader('X-Redmine-API-Key', $memberKey)
            ->getJson('/projects/'.$project.'/wiki/Guide.json')
            ->assertOk()
            ->assertJsonPath('wiki_page.text', 'second');

        $this->withHeader('X-Redmine-API-Key', $adminKey)
            ->putJson('/projects/'.$project.'/wiki/Guide.json', ['wiki_page' => ['text' => 'closed']])
            ->assertForbidden()
            ->assertExactJson($denied);

        $fixture->project->status = Project::STATUS_ACTIVE;
        $fixture->project->save();
        $projects->disableModule($fixture->project->refresh(), 'wiki');
        $projects->disableModule($fixture->project, 'news');

        $this->flushHeaders()
            ->withHeader('X-Redmine-API-Key', $adminKey)
            ->getJson('/projects/'.$project.'/wiki/index.json')
            ->assertForbidden()
            ->assertExactJson($denied);

        $this->withHeader('X-Redmine-API-Key', $adminKey)
            ->postJson('/projects/'.$project.'/news.json', ['news' => ['title' => 'Closed module']])
            ->assertForbidden()
            ->assertExactJson($denied);

        $board = Board::query()->create([
            'project_id' => $fixture->project->id,
            'name' => 'General',
            'position' => 1,
        ]);
        $adminTopic = $this->flushHeaders()
            ->withHeader('X-Redmine-API-Key', $adminKey)
            ->postJson('/boards/'.$board->id.'/topics.json', ['message' => ['subject' => 'Admin topic', 'content' => 'from admin']])
            ->assertCreated()
            ->json('message.id');
        $this->assertIsInt($adminTopic);

        $this->withHeader('X-Redmine-API-Key', $memberKey)
            ->putJson('/messages/'.$adminTopic.'.json', ['message' => ['subject' => 'Taken']])
            ->assertForbidden()
            ->assertExactJson($denied);

        $this->withHeader('X-Redmine-API-Key', $memberKey)
            ->deleteJson('/messages/'.$adminTopic.'.json')
            ->assertForbidden()
            ->assertExactJson($denied);

        $ownTopic = $this->withHeader('X-Redmine-API-Key', $memberKey)
            ->postJson('/boards/'.$board->id.'/topics.json', ['message' => ['subject' => 'Own topic', 'content' => 'from member']])
            ->assertCreated()
            ->json('message.id');
        $this->assertIsInt($ownTopic);

        $this->withHeader('X-Redmine-API-Key', $memberKey)
            ->putJson('/messages/'.$ownTopic.'.json', ['message' => ['subject' => 'Own topic edited']])
            ->assertOk()
            ->assertJsonPath('message.subject', 'Own topic edited');

        $projects->disableModule($fixture->project, 'boards');
        $this->flushHeaders()
            ->withHeader('X-Redmine-API-Key', $adminKey)
            ->getJson('/projects/'.$project.'/boards.json')
            ->assertForbidden()
            ->assertExactJson($denied);
    }

    private function setting(string $name, string $value): void
    {
        Setting::query()->updateOrCreate(['name' => $name], ['value' => $value]);
    }
}
