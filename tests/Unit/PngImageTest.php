<?php

namespace Tests\Unit;

use App\Domain\Attachments\PngImage;
use PHPUnit\Framework\TestCase;

class PngImageTest extends TestCase
{
    public function test_scales_the_longest_edge_and_round_trips_pixels(): void
    {
        $rgb = '';
        for ($y = 0; $y < 2; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $rgb .= $x < 2 ? "\xff\x00\x00" : "\x00\x00\xff";
            }
        }
        $png = PngImage::encode(4, 2, $rgb);
        $this->assertSame([4, 2], PngImage::size($png));

        $fitted = PngImage::fit($png, 2);
        $this->assertSame([2, 1], PngImage::size($fitted));
        $decoded = PngImage::decode($fitted);
        $this->assertSame(2, $decoded['width']);
        $this->assertSame(1, $decoded['height']);
        $this->assertSame("\xff\x00\x00\x00\x00\xff", $decoded['rgb']);
    }
}
