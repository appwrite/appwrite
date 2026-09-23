<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Reference;

use Utopia\OpenAPI\Exception\CircularReference;
use Utopia\OpenAPI\Exception\ReferenceNotFound;

final readonly class LocalResolver implements Resolver
{
    /** @param array<string, mixed> $document */
    public function __construct(private array $document) {}

    #[\Override]
    public function resolve(Reference $reference, ResolutionContext $context = new ResolutionContext()): mixed
    {
        $value = $reference->value;
        if (! $reference->isLocal()) {
            throw new ReferenceNotFound("External reference is not configured: {$value}");
        }

        if (\in_array($value, $context->trail, true)) {
            $trail = implode(' -> ', [...$context->trail, $value]);
            throw new CircularReference("Circular reference: {$trail}");
        }

        $pointer = rawurldecode(substr($value, 1));
        if ($pointer === '') {
            return $this->document;
        }
        if (! str_starts_with($pointer, '/')) {
            throw new ReferenceNotFound("Invalid local JSON Pointer: {$value}");
        }

        $current = $this->document;
        foreach (explode('/', substr($pointer, 1)) as $encodedToken) {
            if (preg_match('/~(?![01])/', $encodedToken) === 1) {
                throw new ReferenceNotFound("Invalid JSON Pointer escape in reference: {$value}");
            }
            $token = str_replace(['~1', '~0'], ['/', '~'], $encodedToken);
            if (! \is_array($current) || ! \array_key_exists($token, $current)) {
                throw new ReferenceNotFound("Reference not found: {$value}");
            }
            $current = $current[$token];
        }

        return $current;
    }

    /**
     * Resolve a chain of Reference Objects. Schema references should not use this
     * method because valid recursive schema graphs must remain unexpanded.
     */
    public function resolveObject(string $reference, array $trail = []): mixed
    {
        $value = $this->resolve(new Reference($reference), new ResolutionContext($trail));
        if (\is_array($value) && isset($value['$ref']) && \is_string($value['$ref'])) {
            return $this->resolveObject($value['$ref'], [...$trail, $reference]);
        }

        return $value;
    }
}
