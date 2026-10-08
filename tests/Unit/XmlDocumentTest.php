<?php

namespace Tests\Unit;

use App\Http\Api\XmlDocument;
use PHPUnit\Framework\TestCase;

class XmlDocumentTest extends TestCase
{
    public function test_list_keeps_element_order_and_collection_attributes(): void
    {
        $xml = (new XmlDocument)->render([
            'issues' => [
                [
                    'id' => 1,
                    'subject' => 'Parent',
                    'due_date' => null,
                    'is_private' => false,
                    'estimated_hours' => 1.5,
                ],
            ],
            'total_count' => 2,
            'offset' => 0,
            'limit' => 1,
        ]);

        $this->assertSame(
            '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<issues type="array" total_count="2" offset="0" limit="1">'
            .'<issue><id>1</id><subject>Parent</subject><due_date nil="true"/><is_private>false</is_private><estimated_hours>1.5</estimated_hours></issue>'
            .'</issues>',
            $xml,
        );
    }

    public function test_irregular_plurals_keep_the_child_name(): void
    {
        $xml = new XmlDocument;
        $this->assertStringContainsString(
            '<news type="array"><news><id>1</id></news></news>',
            $xml->render(['news' => [['id' => 1]]]),
        );
        $this->assertStringContainsString(
            '<children type="array"><child><id>2</id></child></children>',
            $xml->render(['children' => [['id' => 2]]]),
        );
        $this->assertStringContainsString(
            '<issue_status><id>3</id><name>A &amp; B</name></issue_status>',
            $xml->render(['issue_statuses' => [['id' => 3, 'name' => 'A & B']]]),
        );
    }
}
