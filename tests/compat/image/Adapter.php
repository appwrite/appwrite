<?php

namespace Tests\Compat\Image;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Session;
use Utopia\Image\Image;

/**
 * Maps tests/compat/image/spec.json operations onto utopia-php/image. Glue,
 * plus the comparison both adapters compute the same way (see `summary`,
 * `grid` and `compare`): encoded bytes differ between ImageMagick and the Rust
 * codecs, so results are the format read from the magic bytes, the first
 * frame's size, the frame count, the total delay, and pixel comparisons made
 * on 16x16 area-averaged, premultiplied grids.
 */
final class Adapter implements Base, Benchmarks
{
    /** Grid side for pixel comparisons. */
    private const GRID = 16;

    /** Largest mean absolute difference (0-255 channels) two grids may have to be similar. */
    private const TOLERANCE = 6.0;

    private const KITTEN = 'packages/image/tests/resources/disk-a/kitten-1.jpg';

    public function benchmarks(): array
    {
        $kitten = fn () => file_get_contents(self::KITTEN) ?: '';

        return [
            'decode' => ['Decode a 1837x1920 JPEG', function (Session $s) use ($kitten) {
                $blob = $kitten();

                return fn () => new Image($blob);
            }],
            'preview_jpeg' => ['Decode a 1837x1920 JPEG, crop it to 400x400 and encode a JPEG at 80', function (Session $s) use ($kitten) {
                $blob = $kitten();

                return fn () => (new Image($blob))->crop(400, 400)->output('jpg', 80);
            }],
            'preview_gravity' => ['Decode a 1837x1920 JPEG, crop its top 400x200 and encode a PNG', function (Session $s) use ($kitten) {
                $blob = $kitten();

                return fn () => (new Image($blob))->crop(400, 200, Image::GRAVITY_TOP)->output('png', 90);
            }],
            'png_output' => ['Encode a 400x400 image as PNG at quality 90', function (Session $s) use ($kitten) {
                $image = (new Image($kitten()))->crop(400, 400);

                return fn () => $image->output('png', 90);
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'image.new' => fn (array $a, Session $s) => $s->handle(new Image(isset($a['path']) ? (file_get_contents($a['path']) ?: '') : $a['blob'])),
            'image.decode' => fn (array $a, Session $s) => (fn () => ['width' => $this->width, 'height' => $this->height])->call(new Image(($a['magic'] ?? '') . $a['blob'])),
            'pipeline' => function (array $a, Session $s) {
                $image = new Image(file_get_contents($a['path']) ?: '');
                $image->crop($a['width'], $a['height'], $a['gravity'], isset($a['x']) ? (float) $a['x'] : null, isset($a['y']) ? (float) $a['y'] : null);
                if (isset($a['border'])) {
                    $image->setBorder($a['border'], $a['color']);
                }
                if (isset($a['radius'])) {
                    $image->setBorderRadius($a['radius']);
                }
                if (isset($a['opacity'])) {
                    $image->setOpacity((float) $a['opacity']);
                }
                if (isset($a['rotation'])) {
                    $image->setRotation($a['rotation']);
                }
                if (isset($a['background'])) {
                    $image->setBackground($a['background']);
                }

                return self::summary($image->output($a['type'], $a['quality']) ?? '');
            },
            'image.crop' => function (array $a, Session $s) {
                self::image($a, $s)->crop(
                    $a['width'],
                    $a['height'],
                    $a['gravity'] ?? Image::GRAVITY_CENTER,
                    isset($a['x']) ? (float) $a['x'] : null,
                    isset($a['y']) ? (float) $a['y'] : null,
                );

                return;
            },
            'image.set_border' => fn (array $a, Session $s) => self::done(self::image($a, $s)->setBorder($a['width'], $a['color'])),
            'image.set_border_radius' => fn (array $a, Session $s) => self::done(self::image($a, $s)->setBorderRadius($a['radius'])),
            'image.set_opacity' => fn (array $a, Session $s) => self::done(self::image($a, $s)->setOpacity((float) $a['opacity'])),
            'image.set_rotation' => fn (array $a, Session $s) => self::done(self::image($a, $s)->setRotation($a['degree'])),
            'image.set_background' => fn (array $a, Session $s) => self::done(self::image($a, $s)->setBackground($a['color'])),
            'image.output' => fn (array $a, Session $s) => self::summary(self::output($a, $s)),
            'image.save' => function (array $a, Session $s) {
                return self::image($a, $s)->save($a['path'], $a['type'], $a['quality'] ?? 75);
            },
            'image.pixel' => fn (array $a, Session $s) => self::pixel(self::output($a, $s), $a['frame'] ?? 0, $a['x'], $a['y']),
            'image.compare' => fn (array $a, Session $s) => self::compare(self::output($a, $s), file_get_contents($a['path']) ?: '', $a['debug'] ?? false),
            'image.size_by_fixed_height' => fn (array $a, Session $s) => (fn (int $h) => $this->getSizeByFixedHeight($h))->call(self::image($a, $s), $a['height']),
            'image.size_by_fixed_width' => fn (array $a, Session $s) => (fn (int $w) => $this->getSizeByFixedWidth($w))->call(self::image($a, $s), $a['width']),
            'image.gravity_types' => fn (array $a, Session $s) => Image::getGravityTypes(),
            'image.set_resource_limit' => fn (array $a, Session $s) => Image::setResourceLimit($a['type'], $a['value']),
            'file.probe' => fn (array $a, Session $s) => self::summary(file_get_contents($a['path']) ?: ''),
            'file.compare' => fn (array $a, Session $s) => self::compare(file_get_contents($a['path']) ?: '', file_get_contents($a['reference']) ?: '', $a['debug'] ?? false),
        ];
    }

    private static function image(array $a, Session $s): Image
    {
        return $s->get($a['image']);
    }

    private static function done(Image $image): null
    {
        return null;
    }

    private static function output(array $a, Session $s): string
    {
        return self::image($a, $s)->output($a['type'], $a['quality'] ?? 75) ?? '';
    }

    /** The container named by a blob's magic bytes. */
    private static function format(string $blob): string
    {
        return match (true) {
            str_starts_with($blob, "\xFF\xD8\xFF") => 'JPEG',
            str_starts_with($blob, "\x89PNG\r\n\x1a\n") => 'PNG',
            str_starts_with($blob, 'GIF87a'), str_starts_with($blob, 'GIF89a') => 'GIF',
            substr($blob, 0, 4) === 'RIFF' && substr($blob, 8, 4) === 'WEBP' => 'WEBP',
            substr($blob, 4, 4) === 'ftyp' && \in_array(substr($blob, 8, 4), ['avif', 'avis'], true) => 'AVIF',
            substr($blob, 4, 4) === 'ftyp' => 'HEIC',
            default => 'unknown',
        };
    }

    /**
     * @return array{format: string, width: int, height: int, frames: int, delay: int}
     */
    private static function summary(string $blob): array
    {
        $image = new \Imagick();
        $image->readImageBlob($blob);
        $frames = $image->getNumberImages();
        $delay = 0;
        foreach ($image as $frame) {
            $delay += $frame->getImageDelay();
        }
        $image->setFirstIterator();

        return [
            'format' => self::format($blob),
            'width' => $image->getImageWidth(),
            'height' => $image->getImageHeight(),
            'frames' => $frames,
            'delay' => $delay,
        ];
    }

    /**
     * The coalesced frames of a blob as 16x16 grids of premultiplied RGBA
     * (0-255), each cell the area average of the pixels under it.
     *
     * @return list<list<float>>
     */
    private static function grids(string $blob): array
    {
        $image = new \Imagick();
        $image->readImageBlob($blob);
        $grids = [];
        foreach (self::frames($image) as $frame) {
            $cell = clone $frame;
            $cell->scaleImage(self::GRID, self::GRID);
            $pixels = $cell->exportImagePixels(0, 0, self::GRID, self::GRID, 'RGBA', \Imagick::PIXEL_FLOAT);
            $grid = [];
            // HDRI scaling can leave out-of-range colour under (almost) transparent cells.
            $pixels = array_map(fn (float $v) => is_finite($v) ? max(0.0, min(1.0, $v)) : 0.0, $pixels);
            foreach (array_chunk($pixels, 4) as [$r, $g, $b, $alpha]) {
                array_push($grid, $r * $alpha * 255, $g * $alpha * 255, $b * $alpha * 255, $alpha * 255);
            }
            $grids[] = $grid;
        }

        return $grids;
    }

    /**
     * Whether two encoded images match: same first-frame size, same frame
     * count, and every pair of frames within the tolerance (`similar` is null
     * when either side is AVIF or HEIC, which Rust cannot decode).
     *
     * @return array<string, mixed>
     */
    private static function compare(string $blob, string $reference, bool $debug): array
    {
        $a = self::summary($blob);
        $b = self::summary($reference);
        $result = [
            'size' => $a['width'] === $b['width'] && $a['height'] === $b['height'],
            'frames' => $a['frames'] === $b['frames'],
            'similar' => null,
        ];
        if (\in_array($a['format'], ['AVIF', 'HEIC'], true) || \in_array($b['format'], ['AVIF', 'HEIC'], true)) {
            return $result;
        }
        $ga = self::grids($blob);
        $gb = self::grids($reference);
        $worst = 0.0;
        foreach (array_map(null, $ga, $gb) as [$x, $y]) {
            if ($x === null || $y === null) {
                continue;
            }
            $sum = 0.0;
            foreach ($x as $i => $v) {
                $sum += abs($v - $y[$i]);
            }
            $worst = max($worst, $sum / \count($x));
        }
        $result['similar'] = $worst <= self::TOLERANCE;
        if ($debug) {
            $result['mae'] = round($worst, 2);
        }

        return $result;
    }

    /**
     * The frames as a viewer shows them: an animation coalesced, a single
     * frame as stored (ignoring PNG canvas offsets, as browsers and the Rust
     * decoders do).
     */
    private static function frames(\Imagick $image): \Imagick
    {
        if ($image->getNumberImages() > 1) {
            return $image->coalesceImages();
        }
        $image->setImagePage(0, 0, 0, 0);

        return $image;
    }

    /** The channel (r, g or b) strictly brighter than the other two at (x, y) of a frame, or null. */
    private static function pixel(string $blob, int $frame, int $x, int $y): ?string
    {
        $image = new \Imagick();
        $image->readImageBlob($blob);
        $coalesced = self::frames($image);
        $coalesced->setIteratorIndex($frame);
        $color = $coalesced->getImagePixelColor($x, $y)->getColor();
        foreach (['r', 'g', 'b'] as $c) {
            $others = array_diff(['r', 'g', 'b'], [$c]);
            if (array_reduce($others, fn (bool $carry, string $o) => $carry && $color[$c] > $color[$o], true)) {
                return $c;
            }
        }

        return null;
    }
}
