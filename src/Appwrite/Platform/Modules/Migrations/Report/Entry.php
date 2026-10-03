<?php

declare(strict_types=1);

namespace Appwrite\Platform\Modules\Migrations\Report;

final readonly class Entry
{
    public string $json;

    public function __construct(
        public string $resource,
        public string $id,
        public string $status,
        public string $message,
    ) {
        $this->json = \json_encode([
            'resource' => $resource,
            'id' => $id,
            'status' => $status,
            'message' => $message,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
