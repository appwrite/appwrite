<?php

namespace Utopia\Auth\OAuth2;

class ResourceIndicators
{
    /**
     * @var list<non-empty-string>
     */
    private readonly array $resources;

    /**
     * @param array<int, mixed> $resources
     */
    private function __construct(array $resources)
    {
        if ($resources !== array_values($resources)) {
            throw new InvalidResourceException('resources must be a list of absolute URIs.');
        }

        $seen = [];

        foreach ($resources as $resource) {
            switch (true) {
                case !\is_string($resource) || $resource === '':
                    throw new InvalidResourceException('resource must be a non-empty absolute URI.');

                case !$this->isValid($resource):
                    throw new InvalidResourceException('resource must be an absolute HTTP(S) URI with no fragment component.');

                case \in_array($resource, $seen, true):
                    throw new InvalidResourceException('resources must not contain duplicates.');
            }

            $seen[] = $resource;
        }

        /** @var list<non-empty-string> $resources */
        $this->resources = $resources;
    }

    /**
     * @param string|array<int, mixed>|null $value
     * @param string|null $audience Compatibility alias for a single resource indicator.
     *
     * @throws InvalidResourceException
     */
    public static function from(string|array|null $value, ?string $audience = null): self
    {
        if ($value === null || $value === '') {
            $value = [];
        }

        $resources = \is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($resources as $resource) {
            if (!\in_array($resource, $normalized, true)) {
                $normalized[] = $resource;
            }
        }

        $resources = new self($normalized);

        if ($audience === null || $audience === '') {
            return $resources;
        }

        $audienceResource = new self([$audience]);
        if ($resources->resources === []) {
            return $audienceResource;
        }

        if (!$audienceResource->isSubsetOf($resources)) {
            throw new InvalidResourceException('audience must match one of the resource values when both parameters are provided.');
        }

        return $resources;
    }

    /**
     * Requested resources must be a subset of previously granted resources;
     * use this on refresh and token requests per RFC 8707 Section 2.2.
     */
    public function isSubsetOf(self $granted): bool
    {
        return array_diff($this->resources, $granted->resources) === [];
    }

    public function equals(self $resources): bool
    {
        $left = $this->resources;
        $right = $resources->resources;
        sort($left, \SORT_STRING);
        sort($right, \SORT_STRING);

        return $left === $right;
    }

    /**
     * @return list<non-empty-string>
     */
    public function audience(string $defaultAudience): array
    {
        if ($this->resources === []) {
            return [$defaultAudience];
        }

        return $this->resources;
    }

    /**
     * @return list<non-empty-string>
     */
    public function toArray(): array
    {
        return $this->resources;
    }

    private function isValid(string $resource): bool
    {
        $parts = parse_url($resource);

        return \is_array($parts)
            && isset($parts['scheme'])
            && \in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && !isset($parts['fragment'])
            && !empty($parts['host']);
    }
}
