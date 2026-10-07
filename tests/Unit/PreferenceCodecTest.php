<?php

namespace Tests\Unit;

use App\Domain\Auth\AccountValidationException;
use App\Domain\Auth\PreferenceCodec;
use Tests\TestCase;

class PreferenceCodecTest extends TestCase
{
    public function test_json_object_round_trip_ignores_non_json(): void
    {
        $codec = new PreferenceCodec;
        $stored = $codec->encode([
            'comments_sorting' => 'desc',
            'no_self_notified' => '1',
        ]);

        $this->assertSame([
            'comments_sorting' => 'desc',
            'no_self_notified' => true,
        ], $codec->decode($stored));
        $this->assertSame([], $codec->decode("---\n:comments_sorting: desc\n"));
        $this->assertSame([], $codec->decode(''));
    }

    public function test_unknown_key_is_rejected(): void
    {
        $this->expectException(AccountValidationException::class);
        (new PreferenceCodec)->encode(['not_a_preference' => 'desc']);
    }
}
