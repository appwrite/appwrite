<?php

declare(strict_types=1);

namespace Utopia\Tests;

use Utopia\Http\Response;

class MockResponse extends Response
{
    public function end(?string $content = null): void
    {
        if (! \is_null($content)) {
            echo $content;
        }
    }

    public function write(string $content): bool
    {
        echo $content;

        return true;
    }

    protected function sendStatus(int $statusCode): void
    {
        // TODO: Implement sendStatus() method.
    }

    public function sendHeader(string $key, mixed $value): void
    {
        // TODO: Implement sendHeader() method.
    }

    protected function sendCookie(string $name, string $value, array $options): void
    {
        // TODO: Implement sendCookie() method.
    }
}
