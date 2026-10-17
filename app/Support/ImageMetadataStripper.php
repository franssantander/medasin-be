<?php

namespace App\Support;

/**
 * Removes location, camera, and text metadata (EXIF, XMP, IPTC, comments) from JPEG, PNG, and WebP
 * uploads without re-encoding, so pixels, animation, and colour profiles are unchanged. A JPEG
 * keeps only its EXIF orientation, rewritten as a minimal tag, so phone photos stay upright.
 * Other formats and files that do not parse cleanly are left as they are.
 */
class ImageMetadataStripper
{
    /** JPEG segments that carry metadata: APP1 (EXIF/XMP), APP12, APP13 (IPTC/Photoshop), COM. */
    private const JPEG_METADATA_MARKERS = [0xE1, 0xEC, 0xED, 0xFE];

    private const PNG_METADATA_CHUNKS = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];

    private const WEBP_METADATA_CHUNKS = ['EXIF', 'XMP '];

    /**
     * Rewrites the file in place. Returns true when metadata was removed.
     */
    public function strip(string $path): bool
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return false;
        }

        $stripped = match (true) {
            str_starts_with($bytes, "\xFF\xD8") => $this->jpeg($bytes),
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => $this->png($bytes),
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => $this->webp($bytes),
            default => null,
        };

        if ($stripped === null || $stripped === $bytes || file_put_contents($path, $stripped, LOCK_EX) === false) {
            return false;
        }
        clearstatcache(true, $path);

        return true;
    }

    private function jpeg(string $bytes): ?string
    {
        $length = strlen($bytes);
        $kept = '';
        $orientation = null;
        $offset = 2;

        while ($offset < $length) {
            if ($bytes[$offset] !== "\xFF") {
                return null;
            }
            // Markers may be padded with any number of 0xFF fill bytes.
            while ($offset < $length && $bytes[$offset] === "\xFF") {
                $offset++;
            }
            if ($offset >= $length) {
                return null;
            }
            $marker = ord($bytes[$offset]);
            $offset++;

            if ($marker === 0xD9 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $kept .= "\xFF".chr($marker);

                continue;
            }
            if ($offset + 2 > $length) {
                return null;
            }
            $segmentLength = unpack('n', substr($bytes, $offset, 2))[1];
            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                return null;
            }
            $segment = substr($bytes, $offset + 2, $segmentLength - 2);

            if ($marker === 0xDA) {
                // Start of scan: everything from here is compressed image data up to the end marker.
                $kept .= "\xFF\xDA".substr($bytes, $offset);

                return $this->withOrientation("\xFF\xD8", $kept, $orientation);
            }

            if (in_array($marker, self::JPEG_METADATA_MARKERS, true)) {
                if ($marker === 0xE1 && str_starts_with($segment, "Exif\x00\x00")) {
                    $orientation ??= $this->exifOrientation(substr($segment, 6));
                }
            } else {
                $kept .= "\xFF".chr($marker).substr($bytes, $offset, $segmentLength);
            }
            $offset += $segmentLength;
        }

        return null;
    }

    /** Puts a minimal EXIF block holding only the orientation after the JFIF header, when it is not upright. */
    private function withOrientation(string $start, string $segments, ?int $orientation): string
    {
        if ($orientation === null || $orientation === 1) {
            return $start.$segments;
        }

        $tiff = "MM\x00\x2A\x00\x00\x00\x08"
            ."\x00\x01"
            ."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00"
            ."\x00\x00\x00\x00";
        $payload = "Exif\x00\x00".$tiff;
        $exif = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        // JFIF requires its APP0 segment to come first.
        if (str_starts_with($segments, "\xFF\xE0")) {
            $app0Length = unpack('n', substr($segments, 2, 2))[1];

            return $start.substr($segments, 0, 2 + $app0Length).$exif.substr($segments, 2 + $app0Length);
        }

        return $start.$exif.$segments;
    }

    /** Reads the orientation tag (0x0112) from IFD0 of a TIFF block, or null when absent or malformed. */
    private function exifOrientation(string $tiff): ?int
    {
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';
        if (strlen($tiff) < 8) {
            return null;
        }
        $ifd = unpack($long, substr($tiff, 4, 4))[1];
        if ($ifd + 2 > strlen($tiff)) {
            return null;
        }
        $entries = unpack($short, substr($tiff, $ifd, 2))[1];

        for ($index = 0; $index < $entries; $index++) {
            $entry = $ifd + 2 + $index * 12;
            if ($entry + 12 > strlen($tiff)) {
                return null;
            }
            if (unpack($short, substr($tiff, $entry, 2))[1] === 0x0112) {
                $value = unpack($short, substr($tiff, $entry + 8, 2))[1];

                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }

        return null;
    }

    private function png(string $bytes): ?string
    {
        $length = strlen($bytes);
        $kept = substr($bytes, 0, 8);
        $offset = 8;

        while ($offset + 12 <= $length) {
            $dataLength = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            $chunkLength = 12 + $dataLength;
            if ($offset + $chunkLength > $length) {
                return null;
            }
            if (! in_array($type, self::PNG_METADATA_CHUNKS, true)) {
                $kept .= substr($bytes, $offset, $chunkLength);
            }
            $offset += $chunkLength;

            if ($type === 'IEND') {
                return $kept;
            }
        }

        return null;
    }

    private function webp(string $bytes): ?string
    {
        $length = strlen($bytes);
        $chunks = '';
        $offset = 12;
        $removed = false;

        while ($offset + 8 <= $length) {
            $type = substr($bytes, $offset, 4);
            $dataLength = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $chunkLength = 8 + $dataLength + ($dataLength % 2);
            if ($offset + 8 + $dataLength > $length) {
                return null;
            }
            $chunk = substr($bytes, $offset, min($chunkLength, $length - $offset));

            if (in_array($type, self::WEBP_METADATA_CHUNKS, true)) {
                $removed = true;
            } else {
                $chunks .= $chunk;
            }
            $offset += $chunkLength;
        }

        if (! $removed) {
            return $bytes;
        }

        // VP8X flags announce EXIF (0x08) and XMP (0x04) chunks; clear them now the chunks are gone.
        if (str_starts_with($chunks, 'VP8X') && strlen($chunks) >= 9) {
            $chunks[8] = chr(ord($chunks[8]) & ~0x0C);
        }

        return 'RIFF'.pack('V', strlen($chunks) + 4).'WEBP'.$chunks;
    }
}
