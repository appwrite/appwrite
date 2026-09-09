<?php

declare(strict_types=1);

namespace Tests\E2E\General\Certificates;

use Appwrite\Bus\Events\RuleDeleted;
use Utopia\Bus\Listener;

final class Deletion extends Listener
{
    /** @var list<array<string, mixed>> */
    public array $rules = [];

    public static function getName(): string
    {
        return 'deletion';
    }

    public static function getEvents(): array
    {
        return [RuleDeleted::class];
    }

    public function __construct()
    {
        $this->callback(function (RuleDeleted $event): void {
            $this->rules[] = $event->rule;
        });
    }
}
