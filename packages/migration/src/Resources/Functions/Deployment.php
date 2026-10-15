<?php

namespace Utopia\Migration\Resources\Functions;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Deployment extends Resource
{
    public function __construct(
        string $id,
        private readonly Func $func,
        private readonly int $size,
        private readonly string $entrypoint,
        private int $start = 0,
        private int $end = 0,
        private string $data = '',
        private readonly bool $activated = false
    ) {
        $this->id = $id;
    }

    /**
     * @param array<string, mixed> $array
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            Func::fromArray($array['func']),
            $array['size'],
            $array['entrypoint'],
            $array['start'] ?? 0,
            $array['end'] ?? 0,
            $array['data'] ?? '',
            $array['activated'] ?? false
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'func' => $this->func,
            'size' => $this->size,
            'entrypoint' => $this->entrypoint,
            'start' => $this->start,
            'end' => $this->end,
            'data' => $this->data,
            'activated' => $this->activated,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_DEPLOYMENT;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_FUNCTIONS;
    }

    public function getFunction(): Func
    {
        return $this->func;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getEntrypoint(): string
    {
        return $this->entrypoint;
    }

    public function setStart(int $start): self
    {
        $this->start = $start;

        return $this;
    }

    public function getStart(): int
    {
        return $this->start;
    }

    public function setEnd(int $end): self
    {
        $this->end = $end;

        return $this;
    }

    public function getEnd(): int
    {
        return $this->end;
    }

    public function setData(string $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getActivated(): bool
    {
        return $this->activated;
    }
}
