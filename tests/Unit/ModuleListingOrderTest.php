<?php

namespace Tests\Unit;

use App\Domain\Documents\DocumentGroups;
use App\Domain\Files\AttachmentSort;
use App\Domain\Files\VersionOrder;
use PHPUnit\Framework\TestCase;

class ModuleListingOrderTest extends TestCase
{
    public function test_version_order_puts_undated_names_before_later_dates_on_the_files_index(): void
    {
        $this->assertSame(-1, VersionOrder::compare('2026-01-15', 1, '1.0', null, 2, 'Shared down'));
        $this->assertSame(0, VersionOrder::compare('2026-06-01', 5, 'Tree', '2026-06-01', 5, 'Tree'));
        $this->assertLessThan(0, VersionOrder::compare('2026-06-01', 5, 'Tree', '2026-06-01', 8, 'Locked'));

        $rows = [
            ['date' => '2026-01-15', 'id' => 1, 'name' => '1.0'],
            ['date' => null, 'id' => 2, 'name' => 'Shared down'],
            ['date' => '2026-06-01', 'id' => 5, 'name' => 'Tree'],
            ['date' => null, 'id' => 6, 'name' => 'System'],
            ['date' => '2026-06-01', 'id' => 8, 'name' => 'Locked'],
        ];
        usort($rows, function (array $left, array $right): int {
            return VersionOrder::forFiles(
                $left['date'],
                $left['id'],
                $left['name'],
                $right['date'],
                $right['id'],
                $right['name'],
            );
        });

        $this->assertSame([6, 2, 8, 5, 1], array_column($rows, 'id'));
    }

    public function test_attachment_sort_ties_break_on_id(): void
    {
        $rows = [
            ['id' => 3, 'filename' => 'archive.zip', 'created_on' => '2026-08-02 00:00:00', 'filesize' => 200, 'downloads' => 3],
            ['id' => 4, 'filename' => 'Notes.txt', 'created_on' => '2026-08-03 00:00:00', 'filesize' => 50, 'downloads' => 3],
            ['id' => 5, 'filename' => 'readme.txt', 'created_on' => '2026-08-01 00:00:00', 'filesize' => 100, 'downloads' => 1],
        ];

        $this->assertSame([3, 4, 5], AttachmentSort::ids($rows, 'filename'));
        $this->assertSame([4, 3, 5], AttachmentSort::ids($rows, 'created_on'));
        $this->assertSame([3, 5, 4], AttachmentSort::ids($rows, 'size'));
        $this->assertSame([4, 3, 5], AttachmentSort::ids($rows, 'downloads'));
        $this->assertSame([3, 4, 5], AttachmentSort::ids($rows, 'unknown'));
    }

    public function test_document_groups_follow_id_order_except_for_date(): void
    {
        $rows = [
            $this->document(1, 'Zebra', 5, '2026-08-01 00:00:00', '2026-08-01 00:00:00', null),
            $this->document(2, 'alpha guide', 4, '2026-08-02 00:00:00', '2026-08-20 12:00:00', 1),
            $this->document(3, 'Mid', 4, '2026-08-03 00:00:00', '2026-08-12 00:00:00', 1),
            $this->document(4, 'API', 5, '2026-08-04 00:00:00', '2026-08-20 12:00:00', 4),
        ];

        $this->assertSame([
            ['key' => 'category:5', 'document_ids' => [1, 4]],
            ['key' => 'category:4', 'document_ids' => [2, 3]],
        ], DocumentGroups::group($rows, 'category'));
        $this->assertSame([
            ['key' => 'date:2026-08-20', 'document_ids' => [4, 2]],
            ['key' => 'date:2026-08-12', 'document_ids' => [3]],
            ['key' => 'date:2026-08-01', 'document_ids' => [1]],
        ], DocumentGroups::group($rows, 'date'));
        $this->assertSame([
            ['key' => 'title:Z', 'document_ids' => [1]],
            ['key' => 'title:A', 'document_ids' => [2, 4]],
            ['key' => 'title:M', 'document_ids' => [3]],
        ], DocumentGroups::group($rows, 'title'));
        $this->assertSame([
            ['key' => 'author:1', 'document_ids' => [2, 3]],
            ['key' => 'author:4', 'document_ids' => [4]],
        ], DocumentGroups::group($rows, 'author'));
        $this->assertSame(DocumentGroups::group($rows, 'category'), DocumentGroups::group($rows, 'nope'));
    }

    /**
     * @return array{id: int, title: string, category_id: int, created_on: string, updated_on: string, author_id: int|null}
     */
    private function document(
        int $id,
        string $title,
        int $categoryId,
        string $createdOn,
        string $updatedOn,
        ?int $authorId,
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'category_id' => $categoryId,
            'created_on' => $createdOn,
            'updated_on' => $updatedOn,
            'author_id' => $authorId,
        ];
    }
}
