<?php

namespace Inovector\Mixpost\Support;

/**
 * Turns a PNG into the JPEG TikTok photo posts can pull.
 * PNG samples are sRGB. Alpha is composited onto white in that space, and the
 * JPEG is tagged ColorSpace=sRGB.
 */
class TikTokJpeg
{
    public const QUALITY = 95;

    public static function fromPng(string $png): string
    {
        $source = @imagecreatefromstring($png);
        if ($source === false) {
            throw new \RuntimeException('Unable to read the PNG.');
        }
        try {
            $flat = self::flatten($source);
            try {
                return self::encode($flat);
            } finally {
                imagedestroy($flat);
            }
        } finally {
            imagedestroy($source);
        }
    }

    /** @param \GdImage $source */
    private static function flatten($source)
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            throw new \RuntimeException('Unable to convert the PNG.');
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagealphablending($canvas, true);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        return $canvas;
    }

    /** @param \GdImage $image */
    private static function encode($image): string
    {
        ob_start();
        $ok = imagejpeg($image, null, self::QUALITY);
        $bytes = ob_get_clean();
        if ($ok !== true || !is_string($bytes) || !str_starts_with($bytes, "\xFF\xD8")) {
            throw new \RuntimeException('Unable to encode the JPEG.');
        }
        return self::markSrgb($bytes);
    }

    /** Insert an EXIF ColorSpace=sRGB marker after the JPEG start of image. */
    private static function markSrgb(string $jpeg): string
    {
        if (str_contains(substr($jpeg, 0, 80), 'Exif')) {
            return $jpeg;
        }
        $tiff = "MM\x00\x2A\x00\x00\x00\x08";
        $tiff .= "\x00\x01";
        $tiff .= "\xA0\x01\x00\x03\x00\x00\x00\x01\x00\x01\x00\x00";
        $tiff .= "\x00\x00\x00\x00";
        $payload = "Exif\x00\x00".$tiff;
        return "\xFF\xD8\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }
}
