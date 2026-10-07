<?php

namespace Tests\Parity\Support;

/**
 * Reads the checked-in Redmine 7.0.1 structure dump.
 *
 * The file is table, column, index, and foreign-key structure. This parser
 * does not load or execute Redmine application source.
 *
 * @phpstan-type PinColumn array{type: string, nullable: bool, default: ?string, limit: ?int, datetime_precision: ?int}
 * @phpstan-type PinIndex array{unique: bool, columns: list<string>, expression: ?string}
 * @phpstan-type PinTable array{has_id: bool, columns: array<string, PinColumn>, indexes: array<string, PinIndex>}
 * @phpstan-type PinForeignKey array{table: string, column: string, references: string}
 */
final class Redmine701SchemaPin
{
    public const RELATIVE_PATH = 'docs/sources/redmine-7.0.1-schema.rb';

    public const VERSION = '2026_05_20_164915';

    /**
     * Tables in the dump that are outside the P0 layout compare.
     *
     * Repository, git, and SCM tables stay out of the founder loop.
     * News and documents are migrated for the modules row and stay out of
     * this P0 compare. Wiki and forum tables are in the structure compare.
     * Webhooks stay out.
     *
     * @var list<string>
     */
    public const EXCLUDED_TABLES = [
        'repositories',
        'changesets',
        'changes',
        'changeset_parents',
        'changesets_issues',
        'news',
        'documents',
        'webhooks',
        'projects_webhooks',
    ];

    /**
     * P0 tables from docs/schema-inventory.md, plus the migrated `settings` table.
     *
     * @var list<string>
     */
    public const COMPARED_TABLES = [
        'users',
        'email_addresses',
        'tokens',
        'user_preferences',
        'auth_sources',
        'roles',
        'members',
        'member_roles',
        'groups_users',
        'roles_managed_roles',
        'enabled_modules',
        'oauth_applications',
        'oauth_access_grants',
        'oauth_access_tokens',
        'projects',
        'projects_trackers',
        'versions',
        'issue_categories',
        'enumerations',
        'trackers',
        'issue_statuses',
        'issues',
        'issue_relations',
        'watchers',
        'journals',
        'journal_details',
        'workflows',
        'reactions',
        'custom_fields',
        'custom_fields_trackers',
        'custom_fields_projects',
        'custom_fields_roles',
        'custom_values',
        'custom_field_enumerations',
        'time_entries',
        'attachments',
        'comments',
        'queries',
        'queries_roles',
        'imports',
        'import_items',
        'settings',
        'wikis',
        'wiki_pages',
        'wiki_contents',
        'wiki_content_versions',
        'wiki_redirects',
        'boards',
        'messages',
    ];

    /**
     * @param  array<string, PinTable>  $tables
     * @param  list<PinForeignKey>  $foreignKeys
     */
    private function __construct(
        public readonly array $tables,
        public readonly array $foreignKeys,
    ) {}

    public static function parse(): self
    {
        $path = base_path(self::RELATIVE_PATH);
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new Redmine701SchemaPinException(self::RELATIVE_PATH.' is missing.');
        }
        if (! str_contains($raw, 'version: '.self::VERSION)) {
            throw new Redmine701SchemaPinException('schema pin version stamp is not '.self::VERSION.'.');
        }

        $tables = [];
        $foreignKeys = [];
        $current = null;

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            if (preg_match('/^  create_table "([a-z][a-z0-9_]*)"((?:, id: false)?), force: :cascade do \|t\|$/', $line, $opened) === 1) {
                if ($current !== null) {
                    throw new Redmine701SchemaPinException('schema pin opened '.$opened[1].' before '.$current.' closed.');
                }
                $current = $opened[1];
                if (isset($tables[$current])) {
                    throw new Redmine701SchemaPinException('schema pin repeats table '.$current.'.');
                }
                $tables[$current] = [
                    'has_id' => $opened[2] === '',
                    'columns' => [],
                    'indexes' => [],
                ];

                continue;
            }

            if ($line === '  end') {
                if ($current === null) {
                    throw new Redmine701SchemaPinException('schema pin closed a table that was not open.');
                }
                $current = null;

                continue;
            }

            if ($current === null) {
                if (preg_match('/^  add_foreign_key "([a-z][a-z0-9_]*)", "([a-z][a-z0-9_]*)", column: "([a-z][a-z0-9_]*)"$/', $line, $foreignKey) === 1) {
                    $foreignKeys[] = [
                        'table' => $foreignKey[1],
                        'references' => $foreignKey[2],
                        'column' => $foreignKey[3],
                    ];
                }

                continue;
            }

            if (preg_match('/^    t\.(integer|string|text|boolean|datetime|date|float|binary) "([a-z][a-z0-9_]*)"(.*)$/', $line, $column) === 1) {
                $tables[$current]['columns'][$column[2]] = self::column($column[1], $column[3], $current.'.'.$column[2]);

                continue;
            }

            if (preg_match('/^    t\.index (.*)$/', $line, $index) === 1) {
                [$name, $parsed] = self::index($index[1], $current);
                if (isset($tables[$current]['indexes'][$name])) {
                    throw new Redmine701SchemaPinException($current.' repeats index '.$name.'.');
                }
                $tables[$current]['indexes'][$name] = $parsed;

                continue;
            }

            if ($line !== '') {
                throw new Redmine701SchemaPinException('schema pin has an unread line in '.$current.': '.$line);
            }
        }

        if ($current !== null) {
            throw new Redmine701SchemaPinException('schema pin left '.$current.' open.');
        }
        if (count($tables) !== 58) {
            throw new Redmine701SchemaPinException('schema pin must contain 58 core tables.');
        }

        $names = array_keys($tables);
        $excluded = self::EXCLUDED_TABLES;
        $compared = self::COMPARED_TABLES;
        sort($names);
        $expected = array_merge($excluded, $compared);
        sort($expected);
        if ($names !== $expected || count(array_unique($expected)) !== 58) {
            throw new Redmine701SchemaPinException('schema pin tables are not the P0 compare set plus the excluded layers.');
        }

        return new self($tables, $foreignKeys);
    }

    /**
     * @return PinColumn
     */
    private static function column(string $type, string $rest, string $label): array
    {
        $limit = null;
        if (preg_match('/limit: (\d+)/', $rest, $limitMatch) === 1) {
            $limit = (int) $limitMatch[1];
        }

        $default = null;
        if (preg_match('/default: (".*?"|true|false|-?\d+)/', $rest, $defaultMatch) === 1) {
            $raw = $defaultMatch[1];
            if ($raw === 'true') {
                $default = '1';
            } elseif ($raw === 'false') {
                $default = '0';
            } elseif (str_starts_with($raw, '"')) {
                $default = substr($raw, 1, -1);
            } else {
                $default = $raw;
            }
        }

        $datetimePrecision = null;
        if ($type === 'datetime') {
            $datetimePrecision = str_contains($rest, 'precision: nil') ? 0 : 6;
        }

        if ($type === 'integer' && $limit !== null && $limit !== 8) {
            throw new Redmine701SchemaPinException($label.' has an integer limit this compare does not map.');
        }

        return [
            'type' => $type,
            'nullable' => ! str_contains($rest, 'null: false'),
            'default' => $default,
            'limit' => $limit,
            'datetime_precision' => $datetimePrecision,
        ];
    }

    /**
     * @return array{0: string, 1: PinIndex}
     */
    private static function index(string $body, string $table): array
    {
        $columns = [];
        $expression = null;
        if (preg_match('/^\[(.*)\], name: "([^"]+)"(.*)$/', $body, $match) === 1) {
            if (preg_match_all('/"([a-z][a-z0-9_]*)"/', $match[1], $columnMatches) < 1) {
                throw new Redmine701SchemaPinException($table.' has an index without columns.');
            }
            $columns = $columnMatches[1];
            $name = $match[2];
            $tail = $match[3];
        } elseif (preg_match('/^"(\([^"]+\))", name: "([^"]+)"(.*)$/', $body, $match) === 1) {
            $expression = $match[1];
            $name = $match[2];
            $tail = $match[3];
        } else {
            throw new Redmine701SchemaPinException($table.' has an index this compare cannot read: '.$body);
        }

        return [$name, [
            'unique' => str_contains($tail, 'unique: true'),
            'columns' => $columns,
            'expression' => $expression,
        ]];
    }
}
