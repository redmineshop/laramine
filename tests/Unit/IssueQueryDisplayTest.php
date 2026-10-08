<?php

namespace Tests\Unit;

use App\Domain\Queries\IssueQueryDisplay;
use App\Domain\Queries\QueryValidationException;
use PHPUnit\Framework\TestCase;

class IssueQueryDisplayTest extends TestCase
{
    public function test_missing_display_type_is_list(): void
    {
        $this->assertSame(IssueQueryDisplay::LIST, IssueQueryDisplay::resolve(null));
        $this->assertSame(IssueQueryDisplay::LIST, IssueQueryDisplay::resolve([]));
        $this->assertSame(IssueQueryDisplay::LIST, IssueQueryDisplay::resolve(['totalable_names' => []]));
        $this->assertSame(IssueQueryDisplay::LIST, IssueQueryDisplay::resolve(['display_type' => null]));
        $this->assertSame(IssueQueryDisplay::BOARD, IssueQueryDisplay::resolve(['display_type' => 'board'], true));
    }

    public function test_unknown_display_type_is_rejected(): void
    {
        $this->assertDisplayRejected(['display_type' => 'gantt'], 'Query display type is not available: gantt.');
        $this->assertDisplayRejected(['display_type' => 'board'], 'Query display type is not available: board.');
        $this->assertDisplayRejected(['display_type' => ''], 'Query display type is not available.');
        $this->assertDisplayRejected(['display_type' => 1], 'Query display type is not available.');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function assertDisplayRejected(array $options, string $message): void
    {
        try {
            IssueQueryDisplay::resolve($options);
            $this->fail($message);
        } catch (QueryValidationException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
