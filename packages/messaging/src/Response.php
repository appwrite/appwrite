<?php

declare(strict_types=1);

namespace Utopia\Messaging;

class Response
{
    private int $deliveredTo = 0;

    /**
     * @var array<array{recipient: string, status: string, error: string, permanent: bool, code: int}>
     */
    private array $results = [];

    public function __construct(private string $type)
    {
    }

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
     * @return array<array{recipient: string, status: string, error: string, permanent: bool, code: int}>
     */
    public function getDetails(): array
    {
        return $this->results;
    }

    /**
     * @param  bool  $permanent  The provider said sending the same message again gets the same answer,
     *                           such as an SMTP 5xx reply. Only an adapter that can tell says so, which
     *                           is why a failure defaults to one worth retrying.
     * @param  int  $code  The provider's reply code behind the failure, such as an SMTP 535, or 0 when it gave none.
     */
    public function addResult(string $recipient, string $error = '', bool $permanent = false, int $code = 0): void
    {
        $failed = !($error === '' || $error === '0');

        $this->results[] = [
            'recipient' => $recipient,
            'status' => $failed ? 'failure' : 'success',
            'error' => $error,
            'permanent' => $failed && $permanent,
            'code' => $failed ? $code : 0,
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
