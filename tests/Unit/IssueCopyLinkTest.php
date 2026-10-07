<?php

namespace Tests\Unit;

use App\Domain\Issues\History\IssueCopyLink;
use PHPUnit\Framework\TestCase;

class IssueCopyLinkTest extends TestCase
{
    public function test_compose_builds_an_absolute_issue_url(): void
    {
        $this->assertSame(
            'https://tracker.example:8443/issues/12#note-3',
            IssueCopyLink::compose('HTTPS', 'tracker.example:8443', 12, 3),
        );
        $this->assertSame(
            'http://localhost:3000/issues/4#note-1',
            IssueCopyLink::compose('http', '', 4, 1),
        );
        $this->assertSame(
            'http://localhost:3000/issues/4#note-1',
            IssueCopyLink::compose('ftp', 'https://tracker.example/issues', 4, 1),
        );
    }
}
