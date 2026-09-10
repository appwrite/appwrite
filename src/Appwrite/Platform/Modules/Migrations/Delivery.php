<?php

declare(strict_types=1);

namespace Appwrite\Platform\Modules\Migrations;

use Utopia\Database\Document;

final readonly class Delivery
{
    public function __construct(
        public Document $migration,
        public ?Document $terminal,
    ) {
    }
}
