<?php

namespace Appwrite\Autogravity;

use Utopia\Cache\Cache;
use Utopia\Span\Span;

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
            Span::add('autogravity.cache', true);
            return new Gravity((float) $cached['x'], (float) $cached['y']);
        }

        Span::add('autogravity.cache', false);
        Span::add('autogravity.bytes', \strlen($source));

        try {
            $gravity = $this->client->analyze($source);
        } catch (\Throwable $e) {
            Span::add('autogravity.error', $e->getMessage());
            if ($e->getCode() !== 0) {
                Span::add('autogravity.status', $e->getCode());
            }
            throw $e;
        }

        $this->cache->save($key, $gravity->getArrayCopy());

        return $gravity;
    }
}
