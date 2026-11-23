<?php

declare(strict_types=1);

namespace Utopia\Distribution;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\Unavailable;

abstract class Store
{
    protected readonly string $endpoint;

    public function __construct(
        protected readonly ClientInterface $client,
        string $endpoint,
    ) {
        $this->endpoint = rtrim($endpoint, '/');
    }

    abstract public function getName(): string;

    abstract public function account(): Account;

    abstract public function app(string $identifier): App;

    abstract public function latestVersionCode(string $identifier): int;

    /**
     * @param array<string, mixed>|null $checkpoint
     *
     * @return array<string, mixed> external ids
     */
    public function upload(Upload $upload, ?array $checkpoint, ?Progress $progress = null): array
    {
        throw $this->unsupported('upload');
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<string, mixed>|null updated external once processed, null while processing
     */
    public function processed(array $external): ?array
    {
        throw $this->unsupported('processed');
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<string, mixed>
     */
    public function release(Release $release, array $external): array
    {
        throw $this->unsupported('release');
    }

    /**
     * @param array<string, mixed> $external
     */
    public function state(array $external, string $track): State
    {
        throw $this->unsupported('state');
    }

    /**
     * @param array<string, mixed> $external
     */
    public function rollout(array $external, string $track, float $fraction): void
    {
        throw $this->unsupported('rollout');
    }

    /**
     * @param array<string, mixed> $external
     */
    public function halt(array $external, string $track): void
    {
        throw $this->unsupported('halt');
    }

    /**
     * @param array<string, mixed> $external
     */
    public function resume(array $external, string $track): void
    {
        throw $this->unsupported('resume');
    }

    /**
     * @param array<string, mixed> $external
     */
    public function withdraw(array $external): void
    {
        throw $this->unsupported('withdraw');
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<string, mixed>
     */
    public function listing(Listing $listing, array $external): array
    {
        throw $this->unsupported('listing');
    }

    protected function send(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $clientException) {
            throw new Unavailable("{$this->getName()} request failed: {$clientException->getMessage()}", previous: $clientException);
        }
    }

    protected function unsupported(string $operation): Invalid
    {
        return new Invalid("{$operation} is not supported by {$this->getName()}");
    }
}
