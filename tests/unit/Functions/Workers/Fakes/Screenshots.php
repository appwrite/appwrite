<?php

declare(strict_types=1);

namespace Tests\Unit\Functions\Workers\Fakes;

use Appwrite\Platform\Modules\Functions\Workers\Screenshots as Worker;
use Appwrite\Screenshots\Client;
use Utopia\Queue\Message;
use Utopia\Telemetry\Histogram;

final class Screenshots extends Worker
{
    #[\Override]
    public function isAbandoned(\Throwable $error, Message $message): bool
    {
        return parent::isAbandoned($error, $message);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    #[\Override]
    public function captureThemes(Client $screenshots, Histogram $duration, string $url, array $headers, int $sleep): array
    {
        return parent::captureThemes($screenshots, $duration, $url, $headers, $sleep);
    }
}
