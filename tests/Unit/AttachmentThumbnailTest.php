<?php

namespace Tests\Unit;

use App\Domain\Attachments\AttachmentThumbnails;
use App\Domain\Attachments\PdfMagic;
use App\Domain\Attachments\ThumbnailSize;
use PHPUnit\Framework\TestCase;

class AttachmentThumbnailTest extends TestCase
{
    public function test_image_extensions_are_thumbnails_and_other_names_are_not(): void
    {
        $thumbnails = new AttachmentThumbnails;

        $this->assertTrue($thumbnails->isImage('shot.PNG'));
        $this->assertTrue($thumbnails->isImage('folder/diagram.webp'));
        $this->assertTrue($thumbnails->isImage('scan.jpeg'));
        $this->assertTrue($thumbnails->isImage('icon.jpg'));
        $this->assertTrue($thumbnails->isImage('anim.gif'));
        $this->assertTrue($thumbnails->isImage('legacy.bmp'));
        $this->assertTrue($thumbnails->isImage('photo.jpe'));
        $this->assertTrue($thumbnails->isImage('frame.avif'));
        $this->assertFalse($thumbnails->isImage('notes.txt'));
        $this->assertFalse($thumbnails->isImage('picture.svg'));
        $this->assertFalse($thumbnails->isImage('photo.png.txt'));
        $this->assertFalse($thumbnails->isImage('png'));
        $this->assertTrue($thumbnails->isPdfLike('page.PDF'));
        $this->assertTrue($thumbnails->isPdfLike('folder/drawing.ai'));
        $this->assertFalse($thumbnails->isPdfLike('notes.txt'));
        $this->assertTrue($thumbnails->canThumbnail('page.pdf', true));
        $this->assertFalse($thumbnails->canThumbnail('page.pdf', false));
        $this->assertFalse($thumbnails->canThumbnail('notes.txt', true));
    }

    public function test_requested_thumbnail_edge_rounds_up_by_fifty_and_stops_at_800(): void
    {
        $this->assertSame(50, ThumbnailSize::edge(1, 100));
        $this->assertSame(50, ThumbnailSize::edge(2, 100));
        $this->assertSame(50, ThumbnailSize::edge(50, 100));
        $this->assertSame(100, ThumbnailSize::edge(51, 100));
        $this->assertSame(800, ThumbnailSize::edge(800, 100));
        $this->assertSame(800, ThumbnailSize::edge(801, 100));
        $this->assertSame(40, ThumbnailSize::edge(null, 40));
        $this->assertSame(40, ThumbnailSize::edge(0, 40));
        $this->assertSame(1000, ThumbnailSize::edge(null, 1000));
        $this->assertSame(100, ThumbnailSize::edge(null, 0));
    }

    public function test_pdf_magic_accepts_the_header_and_rejects_postscript(): void
    {
        $this->assertTrue(PdfMagic::valid("%PDF-1.4\n"));
        $this->assertTrue(PdfMagic::valid("\xEF\xBB\xBF%PDF-1.7"));
        $this->assertFalse(PdfMagic::valid("%!PS-Adobe-3.0\n"));
        $this->assertFalse(PdfMagic::valid(''));
    }
}
