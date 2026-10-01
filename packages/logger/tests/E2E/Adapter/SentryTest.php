<?php

namespace Utopia\Logger\Tests\E2E\Adapter;

use Utopia\Logger\Adapter\Sentry;
use Utopia\Logger\Tests\E2E\AdapterBase;

class SentryTest extends AdapterBase
{
    #[\Override]
    protected string $credential = 'TEST_SENTRY_DSN';

    protected function setUp(): void
    {
        parent::setUp();
        $this->invalidAdapter = new Sentry('', '', '');

        $dsn = \getenv('TEST_SENTRY_DSN') ?: '';
        if ($dsn === '') {
            return;
        }

        $parsed = parse_url($dsn);
        $host = $parsed['host'] ?? '';
        $path = ltrim($parsed['path'] ?? '', '/');
        $user = $parsed['user'] ?? '';
        $scheme = $parsed['scheme'] ?? '';
        $url = $scheme.'://'.$host;

        $this->adapter = new Sentry($path, $user, $url);
    }
}
