<?php

declare(strict_types=1);

namespace Utopia\UserAgent;

final readonly class Bot
{
    public function __construct(
        public string $name,
        public string $category = 'crawler',
    ) {}

    /**
     * @return array{name: string, category: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
        ];
    }
}
