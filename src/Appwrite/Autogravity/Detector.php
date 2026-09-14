<?php

namespace Appwrite\Autogravity;

use Utopia\Cache\Cache;

class Detector
{
    private const CACHE_TTL = 15_552_000;

    public function __construct(
        private readonly ?Client $client,
        private readonly Cache $cache
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->client !== null;
    }

    public function get(string $source): Gravity
    {
        if ($this->client === null) {
            throw new Exception('Autogravity needs to be configured with _APP_AUTOGRAVITY_HOST to use automatic gravity');
        }

        $key = 'autogravity-' . \hash('sha256', $source);
        $cached = $this->cache->load($key, self::CACHE_TTL);
        if (
            \is_array($cached)
            && isset($cached['x'], $cached['y'])
            && \is_numeric($cached['x'])
            && \is_numeric($cached['y'])
            && $cached['x'] >= 0
            && $cached['x'] <= 1
            && $cached['y'] >= 0
            && $cached['y'] <= 1
        ) {
            return new Gravity((float) $cached['x'], (float) $cached['y']);
        }

        $gravity = $this->client->analyze($this->prepare($source));
        $this->cache->save($key, $gravity->getArrayCopy());

        return $gravity;
    }

    /**
     * Autogravity decodes JPEG, PNG, and WebP. Convert GIF and HEIC so analysis can still run.
     */
    private function prepare(string $source): string
    {
        if (!$this->needsConversion($source)) {
            return $source;
        }

        $image = new \Imagick();
        $image->readImageBlob($source);
        $image->setFirstIterator();
        $frame = $image->getImage();

        $orientation = $frame->getImageProperties()['exif:Orientation'] ?? null;
        $rotation = match ($orientation) {
            '3' => 180,
            '6' => 90,
            '8' => -90,
            default => 0,
        };
        if ($rotation !== 0) {
            $frame->rotateImage(new \ImagickPixel('transparent'), $rotation);
        }
        $frame->setImageFormat('png');
        $frame->stripImage();

        return $frame->getImageBlob();
    }

    private function needsConversion(string $source): bool
    {
        return \str_starts_with($source, 'GIF87a')
            || \str_starts_with($source, 'GIF89a')
            || $this->isHeic($source);
    }

    private function isHeic(string $source): bool
    {
        if (\strlen($source) < 12 || \substr($source, 4, 4) !== 'ftyp') {
            return false;
        }

        $brand = \substr($source, 8, 4);

        return $brand === 'heic'
            || $brand === 'heix'
            || $brand === 'heif'
            || $brand === 'mif1'
            || $brand === 'msf1';
    }
}
