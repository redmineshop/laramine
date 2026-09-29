<?php

namespace App\Domain\Queries;

/**
 * Core issue filters that this engine compiles.
 */
final class IssueFilterCatalog
{
    /**
     * @var array<string, IssueField>|null
     */
    private static ?array $fields = null;

    public static function find(string $name): ?IssueField
    {
        return self::all()[$name] ?? null;
    }

    /**
     * @return array<string, IssueField>
     */
    public static function all(): array
    {
        if (self::$fields !== null) {
            return self::$fields;
        }

        $fields = [
            self::field('status_id', 'list_status', IssueFilterKind::Status, 'status_id'),
            self::field('project_id', 'list', IssueFilterKind::List, 'project_id'),
            self::field('tracker_id', 'list_with_history', IssueFilterKind::List, 'tracker_id'),
            self::field('priority_id', 'list_with_history', IssueFilterKind::List, 'priority_id'),
            self::field('author_id', 'list', IssueFilterKind::List, 'author_id'),
            self::field('assigned_to_id', 'list_optional_with_history', IssueFilterKind::Optional, 'assigned_to_id'),
            self::field('fixed_version_id', 'list_optional_with_history', IssueFilterKind::Optional, 'fixed_version_id'),
            self::field('category_id', 'list_optional_with_history', IssueFilterKind::Optional, 'category_id'),
            self::field('subject', 'text', IssueFilterKind::Text, 'subject'),
            self::field('description', 'text', IssueFilterKind::Text, 'description'),
            self::field('created_on', 'date_past', IssueFilterKind::DateTime, 'created_on'),
            self::field('updated_on', 'date_past', IssueFilterKind::DateTime, 'updated_on'),
            self::field('closed_on', 'date_past', IssueFilterKind::DateTime, 'closed_on'),
            self::field('start_date', 'date', IssueFilterKind::Date, 'start_date'),
            self::field('due_date', 'date', IssueFilterKind::Date, 'due_date'),
            self::field('estimated_hours', 'hour', IssueFilterKind::Float, 'estimated_hours'),
            self::field('done_ratio', 'integer', IssueFilterKind::Integer, 'done_ratio'),
            self::field('is_private', 'list', IssueFilterKind::Bool, 'is_private'),
            self::field('issue_id', 'integer', IssueFilterKind::Integer, 'id'),
            self::field('parent_id', 'tree', IssueFilterKind::Parent, 'parent_id'),
            self::field('child_id', 'tree', IssueFilterKind::Child, 'id'),
            self::field('relates', 'relation', IssueFilterKind::Relation, 'relates'),
            self::field('blocks', 'relation', IssueFilterKind::Relation, 'blocks'),
            self::field('blocked', 'relation', IssueFilterKind::Relation, 'blocked'),
            self::field('duplicates', 'relation', IssueFilterKind::Relation, 'duplicates'),
            self::field('duplicated', 'relation', IssueFilterKind::Relation, 'duplicated'),
            self::field('precedes', 'relation', IssueFilterKind::Relation, 'precedes'),
            self::field('follows', 'relation', IssueFilterKind::Relation, 'follows'),
            self::field('copied_to', 'relation', IssueFilterKind::Relation, 'copied_to'),
            self::field('copied_from', 'relation', IssueFilterKind::Relation, 'copied_from'),
            self::field('author.group', 'list', IssueFilterKind::Association, 'author_id'),
            self::field('author.role', 'list', IssueFilterKind::Association, 'author_id'),
            self::field('member_of_group', 'list_optional', IssueFilterKind::Association, 'assigned_to_id'),
            self::field('assigned_to_role', 'list_optional', IssueFilterKind::Association, 'assigned_to_id'),
            self::field('fixed_version.due_date', 'date', IssueFilterKind::Association, 'fixed_version_id'),
            self::field('fixed_version.status', 'list', IssueFilterKind::Association, 'fixed_version_id'),
            self::field('project.status', 'list', IssueFilterKind::Association, 'project_id'),
            self::field('subproject_id', 'list_subprojects', IssueFilterKind::Subproject, 'project_id'),
            self::field('notes', 'text', IssueFilterKind::Association, 'notes'),
            self::field('attachment', 'text', IssueFilterKind::Association, 'filename'),
            self::field('attachment_description', 'text', IssueFilterKind::Association, 'description'),
            self::field('watcher_id', 'list', IssueFilterKind::Association, 'user_id'),
            self::field('updated_by', 'list', IssueFilterKind::Association, 'user_id'),
            self::field('last_updated_by', 'list', IssueFilterKind::Association, 'user_id'),
            self::field('spent_time', 'hour', IssueFilterKind::Association, 'hours'),
            self::field('any_searchable', 'search', IssueFilterKind::Association, 'subject'),
        ];

        $indexed = [];
        foreach ($fields as $field) {
            $indexed[$field->name] = $field;
        }

        self::$fields = $indexed;

        return $indexed;
    }

    private static function field(string $name, string $filterType, IssueFilterKind $kind, string $column): IssueField
    {
        return new IssueField($name, $filterType, $kind, $column);
    }
}
