<?php

declare(strict_types=1);

namespace Utopia\UserAgent;

final readonly class Device
{
    public function __construct(
        public ?string $type = null,
        public ?string $brand = null,
        public ?string $model = null,
    ) {}

    public function isKnown(): bool
    {
        return $this->type !== null || $this->brand !== null || $this->model !== null;
    }

    /**
     * @return array{type: ?string, brand: ?string, model: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'brand' => $this->brand,
            'model' => $this->model,
        ];
    }
}
