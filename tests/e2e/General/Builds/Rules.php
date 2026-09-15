<?php

declare(strict_types=1);

namespace Tests\E2E\General\Builds;

use Appwrite\Bus\Events\RuleUpdated;
use Utopia\Bus\Listener;

final class Rules extends Listener
{
    public array $updated = [];

    public static function getName(): string
    {
        return 'build-rules';
    }

    public static function getEvents(): array
    {
        return [RuleUpdated::class];
    }

    public function __construct()
    {
        $this->callback(function (RuleUpdated $event): void {
            $this->updated[] = $event->rule;
        });
    }
}
