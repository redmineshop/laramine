<?php

namespace Tests\Unit;

use App\Domain\Attachments\AttachmentThumbnails;
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
        $this->assertFalse($thumbnails->isImage('notes.txt'));
        $this->assertFalse($thumbnails->isImage('picture.svg'));
        $this->assertFalse($thumbnails->isImage('photo.png.txt'));
        $this->assertFalse($thumbnails->isImage('png'));
    }
}
