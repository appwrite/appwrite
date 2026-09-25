<?php

declare(strict_types=1);

namespace Appwrite\Platform\Modules\Migrations;

use Appwrite\Event\Message\Migration as MigrationMessage;
use Utopia\Database\Document;

final readonly class Retry
{
    public function __construct(
        public Document $migration,
        public Document $terminal,
    ) {
    }

    /**
     * @param array<string, mixed> $platform
     */
    public function message(Document $project, array $platform = []): MigrationMessage
    {
        return new MigrationMessage(
            project: $project,
            migration: $this->migration,
            platform: $platform,
            terminal: $this->terminal,
        );
    }
}
