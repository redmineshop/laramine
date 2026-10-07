<?php

namespace App\Domain\Acl;

use InvalidArgumentException;

/**
 * The 80 Redmine 7.0.1 permission names, grouped by module.
 *
 * Flag letters in the table: p public, r read, l require=loggedin, m require=member.
 * The `project` module is not an `enabled_modules` row.
 */
final class PermissionCatalog
{
    public const PROJECT_MODULE = 'project';

    /**
     * @var list<string>
     */
    public const ENABLED_MODULES = [
        'issue_tracking',
        'time_tracking',
        'news',
        'documents',
        'files',
        'wiki',
        'repository',
        'boards',
        'calendar',
        'gantt',
    ];

    /**
     * @var array<string, PermissionDefinition>
     */
    private array $byName = [];

    public function __construct()
    {
        foreach (self::ROWS as $row) {
            [$module, $name, $flags] = $row;
            if (isset($this->byName[$name])) {
                throw new InvalidArgumentException('Duplicate permission name: '.$name);
            }
            $this->byName[$name] = new PermissionDefinition(
                $name,
                $module,
                str_contains($flags, 'p'),
                str_contains($flags, 'r'),
                $this->requireFlag($flags),
            );
        }
    }

    public function definition(string $name): PermissionDefinition
    {
        if (! isset($this->byName[$name])) {
            throw new InvalidArgumentException('Unknown permission: '.$name);
        }

        return $this->byName[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /**
     * @return list<PermissionDefinition>
     */
    public function definitions(): array
    {
        return array_values($this->byName);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    /**
     * @return list<string>
     */
    public function namesForModule(string $module): array
    {
        $names = [];
        foreach ($this->byName as $definition) {
            if ($definition->module === $module) {
                $names[] = $definition->name;
            }
        }

        return $names;
    }

    public function isEnabledModule(string $name): bool
    {
        return in_array($name, self::ENABLED_MODULES, true);
    }

    /**
     * @return 'loggedin'|'member'|null
     */
    private function requireFlag(string $flags): ?string
    {
        if (str_contains($flags, 'm')) {
            return 'member';
        }

        if (str_contains($flags, 'l')) {
            return 'loggedin';
        }

        return null;
    }

    /**
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const ROWS = [
        ['project', 'view_project', 'pr'],
        ['project', 'search_project', 'pr'],
        ['project', 'add_project', 'l'],
        ['project', 'edit_project', 'm'],
        ['project', 'close_project', 'rm'],
        ['project', 'delete_project', 'rm'],
        ['project', 'select_project_publicity', 'm'],
        ['project', 'select_project_modules', 'm'],
        ['project', 'view_members', 'pr'],
        ['project', 'manage_members', 'm'],
        ['project', 'manage_versions', 'm'],
        ['project', 'add_subprojects', 'm'],
        ['project', 'manage_public_queries', 'm'],
        ['project', 'save_queries', 'l'],
        ['project', 'use_webhooks', 'm'],

        ['issue_tracking', 'view_issues', 'r'],
        ['issue_tracking', 'add_issues', ''],
        ['issue_tracking', 'edit_issues', ''],
        ['issue_tracking', 'edit_own_issues', ''],
        ['issue_tracking', 'copy_issues', ''],
        ['issue_tracking', 'manage_issue_relations', ''],
        ['issue_tracking', 'manage_subtasks', ''],
        ['issue_tracking', 'set_issues_private', ''],
        ['issue_tracking', 'set_own_issues_private', 'l'],
        ['issue_tracking', 'add_issue_notes', ''],
        ['issue_tracking', 'edit_issue_notes', 'l'],
        ['issue_tracking', 'edit_own_issue_notes', 'l'],
        ['issue_tracking', 'view_private_notes', 'rm'],
        ['issue_tracking', 'set_notes_private', 'm'],
        ['issue_tracking', 'delete_issues', 'm'],
        ['issue_tracking', 'view_issue_watchers', 'r'],
        ['issue_tracking', 'add_issue_watchers', ''],
        ['issue_tracking', 'delete_issue_watchers', ''],
        ['issue_tracking', 'import_issues', ''],
        ['issue_tracking', 'manage_categories', 'm'],

        ['time_tracking', 'view_time_entries', 'r'],
        ['time_tracking', 'log_time', 'l'],
        ['time_tracking', 'log_time_for_other_users', 'm'],
        ['time_tracking', 'edit_time_entries', ''],
        ['time_tracking', 'edit_own_time_entries', ''],
        ['time_tracking', 'manage_project_activities', ''],
        ['time_tracking', 'import_time_entries', ''],

        ['news', 'view_news', 'r'],
        ['news', 'manage_news', 'm'],
        ['news', 'comment_news', ''],

        ['documents', 'view_documents', 'r'],
        ['documents', 'add_documents', 'l'],
        ['documents', 'edit_documents', 'l'],
        ['documents', 'delete_documents', 'l'],

        ['files', 'view_files', 'r'],
        ['files', 'manage_files', 'l'],

        ['wiki', 'view_wiki_pages', 'r'],
        ['wiki', 'view_wiki_edits', 'r'],
        ['wiki', 'export_wiki_pages', 'r'],
        ['wiki', 'edit_wiki_pages', ''],
        ['wiki', 'rename_wiki_pages', 'm'],
        ['wiki', 'delete_wiki_pages', 'm'],
        ['wiki', 'delete_wiki_pages_attachments', ''],
        ['wiki', 'view_wiki_page_watchers', 'r'],
        ['wiki', 'add_wiki_page_watchers', ''],
        ['wiki', 'delete_wiki_page_watchers', ''],
        ['wiki', 'protect_wiki_pages', 'm'],
        ['wiki', 'manage_wiki', 'm'],

        ['repository', 'view_changesets', 'r'],
        ['repository', 'browse_repository', 'r'],
        ['repository', 'commit_access', ''],
        ['repository', 'manage_related_issues', ''],
        ['repository', 'manage_repository', 'm'],

        ['boards', 'view_messages', 'r'],
        ['boards', 'add_messages', ''],
        ['boards', 'edit_messages', 'm'],
        ['boards', 'edit_own_messages', 'l'],
        ['boards', 'delete_messages', 'm'],
        ['boards', 'delete_own_messages', 'l'],
        ['boards', 'view_message_watchers', 'r'],
        ['boards', 'add_message_watchers', ''],
        ['boards', 'delete_message_watchers', ''],
        ['boards', 'manage_boards', 'm'],

        ['calendar', 'view_calendar', 'r'],
        ['gantt', 'view_gantt', 'r'],
    ];
}
