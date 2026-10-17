<?php

namespace Tests\Unit;

use App\Support\ImageMetadataStripper;
use PHPUnit\Framework\TestCase;

class ImageMetadataStripperTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'strip');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_jpeg_loses_gps_and_comments_but_keeps_its_orientation_and_image_data(): void
    {
        $app0 = "\xFF\xE0".pack('n', 16)."JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
        $quantization = "\xFF\xDB".pack('n', 5)."\x00\x01\x02";
        $scan = "\xFF\xDA".pack('n', 4)."\x01\x02"."\x12\x34\xFF\x00\x56\xFF\xD9";
        $comment = "\xFF\xFE".pack('n', 16).'SECRET-CAMERA!';
        file_put_contents($this->path, "\xFF\xD8".$app0.self::exifWithGps(6).$comment.$quantization.$scan);

        $this->assertTrue((new ImageMetadataStripper)->strip($this->path));

        $stripped = file_get_contents($this->path);
        $this->assertStringNotContainsString('SECRET-CAMERA', $stripped);
        $this->assertStringNotContainsString("\x88\x25", $stripped);
        $this->assertStringStartsWith("\xFF\xD8".$app0."\xFF\xE1", $stripped);
        $this->assertStringContainsString("\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06", $stripped);
        $this->assertStringEndsWith($quantization.$scan, $stripped);
    }

    public function test_upright_jpeg_keeps_no_exif_block_at_all(): void
    {
        $scan = "\xFF\xDA".pack('n', 4)."\x01\x02\x00\xFF\xD9";
        file_put_contents($this->path, "\xFF\xD8".self::exifWithGps(1).$scan);

        $this->assertTrue((new ImageMetadataStripper)->strip($this->path));
        $this->assertSame("\xFF\xD8".$scan, file_get_contents($this->path));
    }

    public function test_png_drops_text_and_exif_chunks(): void
    {
        $header = self::pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0));
        $data = self::pngChunk('IDAT', 'compressed');
        $end = self::pngChunk('IEND', '');
        file_put_contents($this->path, "\x89PNG\r\n\x1A\n".$header.self::pngChunk('tEXt', "Location\x0014.5,121.0")
            .self::pngChunk('eXIf', 'MM')."{$data}{$end}");

        $this->assertTrue((new ImageMetadataStripper)->strip($this->path));
        $this->assertSame("\x89PNG\r\n\x1A\n".$header.$data.$end, file_get_contents($this->path));
    }

    public function test_webp_drops_exif_and_xmp_chunks_and_their_flags(): void
    {
        $vp8x = 'VP8X'.pack('V', 10)."\x0C".str_repeat("\x00", 9);
        $image = 'VP8 '.pack('V', 4).'data';
        $exif = 'EXIF'.pack('V', 3)."GPS\x00";
        $xmp = 'XMP '.pack('V', 4).'<x/>';
        $body = 'WEBP'.$vp8x.$image.$exif.$xmp;
        file_put_contents($this->path, 'RIFF'.pack('V', strlen($body)).$body);

        $this->assertTrue((new ImageMetadataStripper)->strip($this->path));

        $kept = 'VP8X'.pack('V', 10)."\x00".str_repeat("\x00", 9).$image;
        $this->assertSame('RIFF'.pack('V', strlen($kept) + 4).'WEBP'.$kept, file_get_contents($this->path));
    }

    public function test_other_and_malformed_files_are_left_untouched(): void
    {
        foreach (['GIF89a-not-stripped', "\xFF\xD8\xFF\xE1\x00", 'plain text'] as $contents) {
            file_put_contents($this->path, $contents);

            $this->assertFalse((new ImageMetadataStripper)->strip($this->path));
            $this->assertSame($contents, file_get_contents($this->path));
        }
    }

    /** A big-endian APP1 EXIF segment with an orientation and a GPS sub-IFD holding a latitude reference. */
    private static function exifWithGps(int $orientation): string
    {
        $tiff = "MM\x00\x2A".pack('N', 8)
            .pack('n', 2)
            .pack('nnN', 0x0112, 3, 1).pack('n', $orientation)."\x00\x00"
            .pack('nnNN', 0x8825, 4, 1, 38)
            .pack('N', 0)
            .pack('n', 1).pack('nnN', 0x0001, 2, 2)."N\x00\x00\x00".pack('N', 0);
        $payload = "Exif\x00\x00".$tiff;

        return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
