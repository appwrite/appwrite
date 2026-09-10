<?php

namespace Appwrite\GraphQL;

use Appwrite\Extend\Exception as AppwriteException;
use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;
use Utopia\Http\Http;

class Exception extends AppwriteException implements ClientAware, ProvidesExtensions
{
    /**
     * @param array<string, mixed>|null $extensions
     */
    public function __construct(
        string $type = AppwriteException::GENERAL_UNKNOWN,
        ?string $message = null,
        int|string|null $code = null,
        ?\Throwable $previous = null,
        ?string $view = null,
        array $params = [],
        private ?array $extensions = null,
    ) {
        parent::__construct($type, $message, $code, $previous, $view, $params);
    }

    /**
     * @param array{message: string, file?: string, line?: int, trace?: array<mixed>} $payload
     */
    public static function fromResponse(array $payload, int $code): self
    {
        $extensions = null;
        if (Http::isDevelopment()) {
            $extensions = \array_filter([
                'file' => $payload['file'] ?? null,
                'line' => $payload['line'] ?? null,
                'trace' => $payload['trace'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return new self(
            message: $payload['message'],
            code: $code,
            extensions: $extensions ?: null,
        );
    }

    #[\Override]
    public function isClientSafe(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function getExtensions(): ?array
    {
        return $this->extensions;
    }
}
