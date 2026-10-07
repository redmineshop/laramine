<?php

namespace Tests\Parity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\Parity\Support\Redmine701SchemaPin;
use Tests\TestCase;

/**
 * Compares the migrated MySQL 8 layout to the Redmine 7.0.1 structure dump.
 *
 * Repository, git, and SCM tables are excluded. A passing run is evidence for
 * the schema checklist row only.
 */
class SchemaLayoutParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * InnoDB indexes added for foreign-key columns that the dump does not already index.
     * The names are the MySQL 8 set recorded in docs/schema-inventory.md.
     *
     * @var array<string, list<string>>
     */
    private const EXTRA_FOREIGN_KEY_INDEXES = [
        'custom_field_enumerations' => ['custom_field_enumerations_custom_field_id_foreign'],
        'custom_fields_roles' => ['custom_fields_roles_role_id_foreign'],
        'enumerations' => ['enumerations_parent_id_foreign'],
        'groups_users' => ['groups_users_user_id_foreign'],
        'imports' => ['imports_user_id_foreign'],
        'journals' => ['journals_updated_by_id_foreign'],
        'oauth_access_grants' => ['oauth_access_grants_resource_owner_id_foreign'],
        'projects' => [
            'projects_default_assigned_to_id_foreign',
            'projects_default_issue_query_id_foreign',
            'projects_default_version_id_foreign',
            'projects_parent_id_foreign',
        ],
        'queries_roles' => ['queries_roles_role_id_foreign'],
        'roles' => ['roles_default_time_entry_activity_id_foreign'],
        'roles_managed_roles' => ['roles_managed_roles_managed_role_id_foreign'],
        'time_entries' => ['time_entries_author_id_foreign'],
        'trackers' => ['trackers_default_status_id_foreign'],
    ];

    /**
     * Foreign keys Laramine adds beyond the four declared in the structure dump.
     * Columns that use a non-null 0 sentinel stay without a database foreign key.
     *
     * @var list<string>
     */
    private const ADDITIONAL_FOREIGN_KEYS = [
        'custom_field_enumerations.custom_field_id->custom_fields',
        'custom_fields_roles.custom_field_id->custom_fields',
        'custom_fields_roles.role_id->roles',
        'email_addresses.user_id->users',
        'enabled_modules.project_id->projects',
        'enumerations.parent_id->enumerations',
        'enumerations.project_id->projects',
        'groups_users.group_id->users',
        'groups_users.user_id->users',
        'import_items.import_id->imports',
        'imports.user_id->users',
        'issue_categories.assigned_to_id->users',
        'issue_relations.issue_from_id->issues',
        'issue_relations.issue_to_id->issues',
        'issues.assigned_to_id->users',
        'issues.author_id->users',
        'issues.category_id->issue_categories',
        'issues.fixed_version_id->versions',
        'issues.parent_id->issues',
        'issues.priority_id->enumerations',
        'issues.project_id->projects',
        'issues.root_id->issues',
        'issues.status_id->issue_statuses',
        'issues.tracker_id->trackers',
        'journals.updated_by_id->users',
        'member_roles.inherited_from->member_roles',
        'member_roles.member_id->members',
        'member_roles.role_id->roles',
        'projects.default_assigned_to_id->users',
        'projects.default_issue_query_id->queries',
        'projects.default_version_id->versions',
        'projects.parent_id->projects',
        'queries.project_id->projects',
        'queries_roles.query_id->queries',
        'queries_roles.role_id->roles',
        'reactions.user_id->users',
        'roles.default_time_entry_activity_id->enumerations',
        'roles_managed_roles.managed_role_id->roles',
        'roles_managed_roles.role_id->roles',
        'time_entries.activity_id->enumerations',
        'time_entries.author_id->users',
        'time_entries.issue_id->issues',
        'time_entries.project_id->projects',
        'time_entries.user_id->users',
        'trackers.default_status_id->issue_statuses',
        'users.auth_source_id->auth_sources',
        'watchers.user_id->users',
    ];

    public function test_mysql8_p0_layout_matches_schema_pin_and_loaded_fixture(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $version = DB::scalar('select version()');
        $this->assertIsString($version);
        $this->assertMatchesRegularExpression('/^8\./', $version);

        $pin = Redmine701SchemaPin::parse();
        $this->assertCount(58, $pin->tables);
        $this->assertCount(4, $pin->foreignKeys);

        $diffs = $this->layoutDiffs($pin);
        $this->assertSame([], $diffs, "P0 layout differs from the schema pin:\n".implode("\n", $diffs));

        foreach (array_diff(Redmine701SchemaPin::EXCLUDED_TABLES, ['repositories', 'changesets', 'changesets_issues']) as $absent) {
            $this->assertFalse(Schema::hasTable($absent), $absent);
        }

        $loaded = Redmine701Fixture::load();
        $this->assertSame(Redmine701Fixture::PIN, $loaded->pin);
        $this->assertSame(Redmine701Fixture::ORIGIN, $loaded->origin);
        $this->assertSame(Redmine701SchemaPin::RELATIVE_PATH, Redmine701Fixture::SCHEMA_PIN);

        foreach (Redmine701Fixture::SCM_TABLES as $scm) {
            $this->assertArrayNotHasKey($scm, $loaded->counts);
            $this->assertNotContains($scm, Redmine701SchemaPin::COMPARED_TABLES);
        }

        foreach ($loaded->counts as $table => $count) {
            $this->assertContains($table, Redmine701SchemaPin::COMPARED_TABLES);
            $this->assertSame($count, DB::table($table)->count(), $table);
            $this->assertFixtureColumnsAreOnThePin($pin, $table);
        }

        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression(
            '/^\| P0 table and column layout \| VERIFIED \|.*tests\/Parity\/SchemaLayoutParityTest\.php.*tests\/Parity\/fixtures\/redmine-7\.0\.1\/.*docs\/sources\/redmine-7\.0\.1-schema\.rb/m',
            $checklist,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\| (?!P0 table and column layout \|)(?!Users and authentication \|)(?!Identity, membership, and permissions \|)(?!Projects and issue nested sets \|)(?!Workflows \|)(?!Custom fields \|)[^|\n]+\| VERIFIED \|/m',
            $checklist,
        );
    }

    /**
     * @return list<string>
     */
    private function layoutDiffs(Redmine701SchemaPin $pin): array
    {
        $diffs = [];
        $liveColumns = $this->liveColumns();
        $liveIndexes = $this->liveIndexes();
        $liveForeignKeys = $this->liveForeignKeys();

        foreach (Redmine701SchemaPin::COMPARED_TABLES as $table) {
            $spec = $pin->tables[$table];
            if (! isset($liveColumns[$table])) {
                $diffs[] = $table.' is missing from MySQL';

                continue;
            }

            $expectedColumns = array_keys($spec['columns']);
            if ($spec['has_id']) {
                $expectedColumns[] = 'id';
            }
            sort($expectedColumns);
            $actualColumns = array_keys($liveColumns[$table]);
            sort($actualColumns);
            if ($expectedColumns !== $actualColumns) {
                $diffs[] = $table.' columns expected ['.implode(', ', $expectedColumns).'] live ['.implode(', ', $actualColumns).']';
            }

            foreach ($spec['columns'] as $name => $column) {
                if (! isset($liveColumns[$table][$name])) {
                    continue;
                }
                $live = $liveColumns[$table][$name];
                $expectedType = $this->expectedColumnType($column);
                if ($live['column_type'] !== $expectedType || str_contains($live['column_type'], 'unsigned')) {
                    $diffs[] = $table.'.'.$name.' type expected '.$expectedType.' live '.$live['column_type'];
                }
                if ($column['type'] === 'datetime' && $live['datetime_precision'] !== $column['datetime_precision']) {
                    $diffs[] = $table.'.'.$name.' datetime precision expected '.(string) $column['datetime_precision'].' live '.(string) $live['datetime_precision'];
                }
                if ($live['nullable'] !== $column['nullable']) {
                    $diffs[] = $table.'.'.$name.' nullable expected '.($column['nullable'] ? 'yes' : 'no').' live '.($live['nullable'] ? 'yes' : 'no');
                }
                if ($live['default'] !== $column['default']) {
                    $diffs[] = $table.'.'.$name.' default expected '.var_export($column['default'], true).' live '.var_export($live['default'], true);
                }
            }

            if ($spec['has_id']) {
                $id = $liveColumns[$table]['id'] ?? null;
                if ($id === null || $id['column_type'] !== 'int' || $id['nullable'] || ! str_contains($id['extra'], 'auto_increment') || $id['column_key'] !== 'PRI') {
                    $diffs[] = $table.'.id is not a signed auto-increment primary key';
                }
            }

            $diffs = array_merge($diffs, $this->indexDiffs($table, $spec['indexes'], $liveIndexes[$table] ?? [], $spec['has_id']));
        }

        $expectedForeignKeys = [];
        foreach ($pin->foreignKeys as $foreignKey) {
            $expectedForeignKeys[] = $foreignKey['table'].'.'.$foreignKey['column'].'->'.$foreignKey['references'];
        }
        $expectedForeignKeys = array_merge($expectedForeignKeys, self::ADDITIONAL_FOREIGN_KEYS);
        sort($expectedForeignKeys);
        sort($liveForeignKeys);
        if ($expectedForeignKeys !== $liveForeignKeys) {
            $diffs[] = 'foreign keys expected ['.implode(', ', $expectedForeignKeys).'] live ['.implode(', ', $liveForeignKeys).']';
        }

        return $diffs;
    }

    /**
     * @param  array<string, array{unique: bool, columns: list<string>, expression: ?string}>  $expected
     * @param  array<string, array{unique: bool, parts: list<string>}>  $live
     * @return list<string>
     */
    private function indexDiffs(string $table, array $expected, array $live, bool $hasId): array
    {
        $diffs = [];
        $expectedNames = array_keys($expected);
        if ($hasId) {
            $primary = $live['PRIMARY'] ?? null;
            if ($primary === null || $primary['parts'] !== ['id'] || ! $primary['unique']) {
                $diffs[] = $table.' primary key is not id';
            }
        } elseif (isset($live['PRIMARY'])) {
            $diffs[] = $table.' has a primary key the dump does not declare';
        }

        foreach ($expected as $name => $index) {
            $actual = $live[$name] ?? null;
            if ($actual === null) {
                $diffs[] = $table.' missing index '.$name;

                continue;
            }
            $parts = $index['expression'] !== null
                ? [$this->normalizeExpression($index['expression'])]
                : $index['columns'];
            if ($actual['unique'] !== $index['unique'] || $actual['parts'] !== $parts) {
                $diffs[] = $table.' index '.$name.' expected '.implode(',', $parts).' unique='.($index['unique'] ? 'yes' : 'no')
                    .' live '.implode(',', $actual['parts']).' unique='.($actual['unique'] ? 'yes' : 'no');
            }
        }

        $allowedExtra = self::EXTRA_FOREIGN_KEY_INDEXES[$table] ?? [];
        sort($allowedExtra);
        $extra = array_values(array_diff(array_keys($live), $expectedNames, ['PRIMARY']));
        sort($extra);
        if ($extra !== $allowedExtra) {
            $diffs[] = $table.' extra indexes expected ['.implode(', ', $allowedExtra).'] live ['.implode(', ', $extra).']';
        }

        return $diffs;
    }

    /**
     * @param  array{type: string, nullable: bool, default: ?string, limit: ?int, datetime_precision: ?int}  $column
     */
    private function expectedColumnType(array $column): string
    {
        return match ($column['type']) {
            'integer' => $column['limit'] === 8 ? 'bigint' : 'int',
            'string' => 'varchar('.($column['limit'] ?? 255).')',
            'text' => 'text',
            'boolean' => 'tinyint(1)',
            'date' => 'date',
            'float' => 'float',
            'datetime' => $column['datetime_precision'] === 0 ? 'datetime' : 'datetime('.$column['datetime_precision'].')',
            default => 'unmapped-'.$column['type'],
        };
    }

    private function normalizeExpression(string $expression): string
    {
        return str_replace(['`', ' ', '(', ')'], '', strtolower($expression));
    }

    private function assertFixtureColumnsAreOnThePin(Redmine701SchemaPin $pin, string $table): void
    {
        $path = Redmine701Fixture::directory().'/'.$table.'.json';
        $decoded = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $spec = $pin->tables[$table];
        foreach ($decoded as $row) {
            $this->assertInstanceOf(stdClass::class, $row);
            foreach (array_keys(get_object_vars($row)) as $column) {
                $this->assertTrue(
                    $column === 'id' ? $spec['has_id'] : isset($spec['columns'][$column]),
                    $table.'.'.$column.' is in the shared fixture and not on the schema pin',
                );
            }
        }
    }

    /**
     * @return array<string, array<string, array{column_type: string, nullable: bool, default: ?string, extra: string, column_key: string, datetime_precision: ?int}>>
     */
    private function liveColumns(): array
    {
        $rows = DB::select(
            'select TABLE_NAME as table_name, COLUMN_NAME as column_name, COLUMN_TYPE as column_type, IS_NULLABLE as is_nullable, COLUMN_DEFAULT as column_default, EXTRA as extra, COLUMN_KEY as column_key, DATETIME_PRECISION as datetime_precision from information_schema.COLUMNS where TABLE_SCHEMA = database()',
        );
        $columns = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }
            $precision = $row->datetime_precision;
            $columns[(string) $row->table_name][(string) $row->column_name] = [
                'column_type' => (string) $row->column_type,
                'nullable' => $row->is_nullable === 'YES',
                'default' => $row->column_default === null ? null : (string) $row->column_default,
                'extra' => (string) $row->extra,
                'column_key' => (string) $row->column_key,
                'datetime_precision' => $precision === null ? null : (int) $precision,
            ];
        }

        return $columns;
    }

    /**
     * @return array<string, array<string, array{unique: bool, parts: list<string>}>>
     */
    private function liveIndexes(): array
    {
        $rows = DB::select(
            'select TABLE_NAME as table_name, INDEX_NAME as index_name, NON_UNIQUE as non_unique, COLUMN_NAME as column_name, EXPRESSION as expression from information_schema.STATISTICS where TABLE_SCHEMA = database() order by TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
        );
        $indexes = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }
            $table = (string) $row->table_name;
            $name = (string) $row->index_name;
            $indexes[$table][$name] ??= [
                'unique' => (string) $row->non_unique === '0',
                'parts' => [],
            ];
            $expression = $row->expression;
            $indexes[$table][$name]['parts'][] = is_string($expression) && $expression !== ''
                ? $this->normalizeExpression($expression)
                : (string) $row->column_name;
        }

        return $indexes;
    }

    /**
     * @return list<string>
     */
    private function liveForeignKeys(): array
    {
        $rows = DB::select(
            'select TABLE_NAME as table_name, COLUMN_NAME as column_name, REFERENCED_TABLE_NAME as referenced_table from information_schema.KEY_COLUMN_USAGE where TABLE_SCHEMA = database() and REFERENCED_TABLE_NAME is not null order by TABLE_NAME, COLUMN_NAME',
        );
        $compared = array_flip(Redmine701SchemaPin::COMPARED_TABLES);
        $keys = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }
            $table = (string) $row->table_name;
            if (! isset($compared[$table])) {
                continue;
            }
            $keys[] = $table.'.'.(string) $row->column_name.'->'.(string) $row->referenced_table;
        }
        sort($keys);

        return $keys;
    }
}
