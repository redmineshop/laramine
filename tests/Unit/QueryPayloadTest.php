<?php

namespace Tests\Unit;

use App\Domain\Queries\OperatorMatrix;
use App\Domain\Queries\QueryPayload;
use App\Domain\Queries\QueryValidationException;
use PHPUnit\Framework\TestCase;

class QueryPayloadTest extends TestCase
{
    public function test_yaml_filter_map_becomes_the_json_shape(): void
    {
        $yaml = <<<'YAML'
status_id:
  :values: []
  :operator: o
cf_1:
  :values:
    - MySQL
  :operator: "="
YAML;

        $filters = QueryPayload::filters($yaml);

        $this->assertSame([
            'status_id' => ['operator' => 'o', 'values' => []],
            'cf_1' => ['operator' => '=', 'values' => ['MySQL']],
        ], $filters);
        $this->assertSame(
            '{"status_id":{"operator":"o","values":[]},"cf_1":{"operator":"=","values":["MySQL"]}}',
            QueryPayload::encodeFilters($filters),
        );
    }

    public function test_empty_redmine_document_and_list_form_are_accepted(): void
    {
        $this->assertSame([], QueryPayload::filters("--- {}\n"));
        $this->assertSame(
            ['status_id' => ['operator' => 'o', 'values' => []]],
            QueryPayload::filters([
                ['field' => 'status_id', 'op' => 'o', 'values' => []],
            ]),
        );
    }

    public function test_yaml_columns_sort_and_options_drop_symbol_markers(): void
    {
        $this->assertSame(
            ['tracker', 'status', 'subject'],
            QueryPayload::columnNames("---\n- :tracker\n- :status\n- subject\n"),
        );
        $this->assertSame(
            [['priority', 'desc'], ['tracker', 'asc']],
            QueryPayload::sort("---\n- - :priority\n  - desc\n- - tracker\n  - asc\n"),
        );
        $this->assertSame(
            ['display_type' => 'list', 'totalable_names' => []],
            QueryPayload::options("---\n:display_type: list\n:totalable_names: []\n"),
        );
    }

    public function test_invalid_json_is_rejected(): void
    {
        $this->expectException(QueryValidationException::class);

        QueryPayload::filters('{not json');
    }

    public function test_operator_catalog_counts(): void
    {
        $this->assertCount(41, OperatorMatrix::SHIPPED);
        $this->assertSame([], OperatorMatrix::DEFERRED);
        $this->assertCount(41, array_unique(OperatorMatrix::SHIPPED));
        $this->assertSame('shipped', OperatorMatrix::acceptance('list_status', 'o'));
        $this->assertSame('shipped', OperatorMatrix::acceptance('list_status', 'ev'));
        $this->assertSame('shipped', OperatorMatrix::acceptance('tree', '*'));
        $this->assertSame('shipped', OperatorMatrix::acceptance('text', '='));
        $this->assertSame('invalid', OperatorMatrix::acceptance('text', 'o'));
        $this->assertSame('invalid', OperatorMatrix::acceptance('integer', '!'));
        $this->assertSame('invalid', OperatorMatrix::acceptance('float', '!'));
        $this->assertSame('invalid', OperatorMatrix::acceptance('hour', '!'));
        $this->assertSame('invalid', OperatorMatrix::acceptance('date_past', 'nd'));
        $this->assertSame('one', OperatorMatrix::valueMode('><t+'));
        $this->assertSame('none', OperatorMatrix::valueMode('*o'));
        $this->assertSame('two', OperatorMatrix::valueMode('><'));
        $this->assertSame(
            ['=', '>=', '<=', '><', '>t-', '<t-', '><t-', 't-', 't', 'ld', 'w', 'lw', 'l2w', 'm', 'lm', 'y', '!*', '*'],
            OperatorMatrix::shippedFor('date_past'),
        );
    }
}
