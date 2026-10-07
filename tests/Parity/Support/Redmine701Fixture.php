<?php

namespace Tests\Parity\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use stdClass;

/**
 * Loads the invented Redmine 7.0.1-shaped pin into the migrated MySQL 8 database.
 *
 * The pin is test data. Loading it does not compare Laramine to Redmine and
 * does not mark a parity checklist row VERIFIED.
 */
final class Redmine701Fixture
{
    public const PIN = '7.0.1';

    public const LABEL = 'redmine-7.0.1-shaped';

    public const ORIGIN = 'invented';

    public const SCHEMA_PIN = 'docs/sources/redmine-7.0.1-schema.rb';

    public const EXPECTATIONS = 'expectations';

    /**
     * Repository and changeset tables stay out of this pin.
     *
     * @var list<string>
     */
    public const SCM_TABLES = [
        'repositories',
        'changesets',
        'changes',
        'changeset_parents',
        'changesets_issues',
    ];

    /**
     * @param  array<string, int>  $counts
     */
    private function __construct(
        public readonly string $pin,
        public readonly string $origin,
        public readonly array $counts,
    ) {}

    public static function directory(): string
    {
        return dirname(__DIR__).'/fixtures/redmine-7.0.1';
    }

    public static function load(): self
    {
        self::assertMysql8();

        $manifest = self::manifest();
        $counts = [];

        DB::transaction(function () use ($manifest, &$counts): void {
            foreach ($manifest['load_order'] as $table) {
                $counts[$table] = self::insertTable($table, $manifest['tables'][$table]);
            }
        });

        return new self($manifest['pin'], $manifest['origin'], $counts);
    }

    /**
     * Next explicit id for a pin table that has an `id` column.
     *
     * Does not run DDL. MySQL treats AUTO_INCREMENT changes as a commit, which
     * would break the test transaction.
     */
    public function nextId(string $table): int
    {
        if (! isset($this->counts[$table])) {
            throw new Redmine701FixtureException($table.' is not in the loaded pin.');
        }

        $max = DB::table($table)->max('id');
        if (! is_numeric($max)) {
            throw new Redmine701FixtureException($table.' has no numeric id to continue from.');
        }

        return ((int) $max) + 1;
    }

    /**
     * @return array{
     *     pin: string,
     *     label: string,
     *     origin: string,
     *     origin_note: string,
     *     schema_pin: string,
     *     excludes: list<string>,
     *     load_order: list<string>,
     *     expectations: string,
     *     tables: array<string, array{file: string, rows: int}>
     * }
     */
    private static function manifest(): array
    {
        $path = self::directory().'/manifest.json';
        $decoded = self::decodeJson($path, true);
        if (! is_array($decoded)) {
            throw new Redmine701FixtureException('manifest.json must be an object.');
        }

        $pin = self::stringField($decoded, 'pin');
        $label = self::stringField($decoded, 'label');
        $origin = self::stringField($decoded, 'origin');
        $originNote = self::stringField($decoded, 'origin_note');
        $schemaPin = self::stringField($decoded, 'schema_pin');
        $expectations = self::stringField($decoded, 'expectations');
        if ($pin !== self::PIN || $label !== self::LABEL || $origin !== self::ORIGIN) {
            throw new Redmine701FixtureException('manifest pin, label, or origin does not match the 7.0.1-shaped pin.');
        }
        if ($originNote === '') {
            throw new Redmine701FixtureException('manifest origin_note must say this pin was invented.');
        }
        if ($schemaPin !== self::SCHEMA_PIN) {
            throw new Redmine701FixtureException('manifest schema_pin must stay the structure dump, not a data file.');
        }
        if ($expectations !== self::EXPECTATIONS) {
            throw new Redmine701FixtureException('manifest expectations must be the expectations directory.');
        }

        $excludes = self::stringList($decoded, 'excludes');
        if ($excludes !== self::SCM_TABLES) {
            throw new Redmine701FixtureException('manifest excludes must list the SCM tables and nothing else.');
        }

        $loadOrder = self::stringList($decoded, 'load_order');
        $tables = self::tableSpecs($decoded, $loadOrder);
        foreach (self::SCM_TABLES as $scm) {
            if (in_array($scm, $loadOrder, true)) {
                throw new Redmine701FixtureException($scm.' is an SCM table and is not loaded.');
            }
        }

        return [
            'pin' => $pin,
            'label' => $label,
            'origin' => $origin,
            'origin_note' => $originNote,
            'schema_pin' => $schemaPin,
            'excludes' => $excludes,
            'load_order' => $loadOrder,
            'expectations' => $expectations,
            'tables' => $tables,
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private static function insertTable(string $table, array $spec): int
    {
        if (! Schema::hasTable($table)) {
            throw new Redmine701FixtureException($table.' is not a migrated table.');
        }
        if (DB::table($table)->count() !== 0) {
            throw new Redmine701FixtureException($table.' is not empty. Load the pin into a fresh migrated database.');
        }

        $path = self::directory().'/'.$spec['file'];
        $decoded = self::decodeJson($path, false);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new Redmine701FixtureException($spec['file'].' must be a JSON array of rows.');
        }
        if (count($decoded) !== $spec['rows']) {
            throw new Redmine701FixtureException($spec['file'].' row count does not match the manifest.');
        }

        $seenIds = [];
        foreach ($decoded as $index => $row) {
            if (! $row instanceof stdClass) {
                throw new Redmine701FixtureException($spec['file'].' row '.$index.' must be an object.');
            }
            $insert = self::rowToInsert($row, $table);
            if (array_key_exists('id', $insert)) {
                $id = $insert['id'];
                if (! is_int($id)) {
                    throw new Redmine701FixtureException($table.' id must be an integer.');
                }
                if (isset($seenIds[$id])) {
                    throw new Redmine701FixtureException($table.' repeats id '.$id.'.');
                }
                $seenIds[$id] = true;
            }
            DB::table($table)->insert($insert);
        }

        return count($decoded);
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private static function rowToInsert(stdClass $row, string $table): array
    {
        $insert = [];
        foreach (get_object_vars($row) as $column => $value) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $column) !== 1) {
                throw new Redmine701FixtureException($table.' has a column name that is not a safe identifier.');
            }
            $insert[$column] = self::columnValue($value, $table, $column);
        }

        return $insert;
    }

    private static function columnValue(mixed $value, string $table, string $column): int|float|string|null
    {
        if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value instanceof stdClass || is_array($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException $exception) {
                throw new Redmine701FixtureException($table.'.'.$column.' could not be stored as JSON.', previous: $exception);
            }
        }

        throw new Redmine701FixtureException($table.'.'.$column.' has a value the pin loader does not store.');
    }

    private static function assertMysql8(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            throw new Redmine701FixtureException('Parity fixtures load on MySQL only.');
        }

        $version = DB::scalar('select version()');
        if (! is_string($version) || preg_match('/^8\./', $version) !== 1) {
            throw new Redmine701FixtureException('Parity fixtures require MySQL 8.');
        }
    }

    private static function decodeJson(string $path, bool $associative): mixed
    {
        $directory = self::directory();
        if (! str_starts_with($path, $directory.'/') || str_contains(substr($path, strlen($directory) + 1), '/')) {
            throw new Redmine701FixtureException('Fixture path escapes the pin directory.');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new Redmine701FixtureException(basename($path).' is missing.');
        }

        try {
            return json_decode($raw, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new Redmine701FixtureException(basename($path).' is not valid JSON.', previous: $exception);
        }
    }

    /**
     * @param  array<mixed>  $decoded
     */
    private static function stringField(array $decoded, string $key): string
    {
        $value = $decoded[$key] ?? null;
        if (! is_string($value)) {
            throw new Redmine701FixtureException('manifest '.$key.' must be a string.');
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<string>
     */
    private static function stringList(array $decoded, string $key): array
    {
        $value = $decoded[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new Redmine701FixtureException('manifest '.$key.' must be a list.');
        }

        $list = [];
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new Redmine701FixtureException('manifest '.$key.' must contain names.');
            }
            $list[] = $item;
        }

        return $list;
    }

    /**
     * @param  array<mixed>  $decoded
     * @param  list<string>  $loadOrder
     * @return array<string, array{file: string, rows: int}>
     */
    private static function tableSpecs(array $decoded, array $loadOrder): array
    {
        $tables = $decoded['tables'] ?? null;
        if (! is_array($tables)) {
            throw new Redmine701FixtureException('manifest tables must be an object.');
        }

        $specs = [];
        foreach ($tables as $table => $spec) {
            if (! is_string($table) || preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
                throw new Redmine701FixtureException('manifest tables has a name that is not a safe identifier.');
            }
            if (! is_array($spec)) {
                throw new Redmine701FixtureException('manifest table '.$table.' must be an object.');
            }
            $file = $spec['file'] ?? null;
            $rows = $spec['rows'] ?? null;
            if ($file !== $table.'.json' || ! is_int($rows) || $rows < 1) {
                throw new Redmine701FixtureException('manifest table '.$table.' must point at '.$table.'.json with a positive row count.');
            }
            $specs[$table] = ['file' => $file, 'rows' => $rows];
        }

        $names = array_keys($specs);
        $order = $loadOrder;
        sort($names);
        sort($order);
        if ($names !== $order) {
            throw new Redmine701FixtureException('manifest load_order must name each table once.');
        }

        return $specs;
    }
}
