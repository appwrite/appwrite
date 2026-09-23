<?php

declare(strict_types=1);

namespace Utopia\Messaging;

class Response
{
    private int $deliveredTo = 0;

    /**
     * @var array<array<string, string>>
     */
    private array $results = [];

    public function __construct(private string $type) {}

    public function setDeliveredTo(int $deliveredTo): void
    {
        $this->deliveredTo = $deliveredTo;
    }

    public function incrementDeliveredTo(): void
    {
        $this->deliveredTo++;
    }

    public function getDeliveredTo(): int
    {
        return $this->deliveredTo;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<array<string, string>>
     */
    public function getDetails(): array
    {
        return $this->results;
    }

    public function addResult(string $recipient, string $error = ''): void
    {
        $this->results[] = [
            'recipient' => $recipient,
            'status' => $error === '' || $error === '0' ? 'success' : 'failure',
            'error' => $error,
        ];
    }

    /**
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'deliveredTo' => $this->deliveredTo,
            'type' => $this->type,
            'results' => $this->results,
        ];
    }
}
