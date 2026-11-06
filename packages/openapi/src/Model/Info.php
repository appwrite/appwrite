<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class Info
{
    /** @param array<string, mixed> $extensions */
    public function __construct(
        public string $title,
        public string $description,
        public string $version,
        public ?string $termsOfService = null,
        public ?Contact $contact = null,
        public ?License $license = null,
        public array $extensions = [],
    ) {
    }
}
