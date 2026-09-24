<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

use Utopia\Auth\Enums\AuthorizationDetail;

/**
 * RFC 9396 Rich Authorization Requests: the `authorization_details` a token
 * carries, as an immutable list of typed entries. Reads whether an entry of a
 * given type lists a value in one of its array-valued fields, and narrows a
 * field to the values a resolver allows. Malformed entries and fields are
 * ignored, never trusted.
 */
class AuthorizationDetails
{
    /**
     * @var list<array<string, mixed>>
     */
    private readonly array $entries;

    /**
     * Accepts a raw `authorization_details` value. Anything that is not a list
     * of objects contributes nothing, so callers need not pre-validate.
     */
    public function __construct(mixed $value)
    {
        $entries = [];
        if (\is_array($value) && array_is_list($value)) {
            foreach ($value as $entry) {
                if (\is_array($entry)) {
                    $entries[] = $entry;
                }
            }
        }

        $this->entries = $entries;
    }

    /**
     * Whether an entry of $type lists $value in its $field. $field is an
     * array-valued field name — an RFC 9396 common field (AuthorizationDetail's
     * Locations, Actions, Datatypes, Privileges) or one a type defines itself.
     * RFC 9396 has no wildcard, so a caller that treats one identifier as
     * matching every value passes it as $wildcard to opt that field in.
     */
    public function grants(string $type, string $value, string $field, ?string $wildcard = null): bool
    {
        if ($type === '' || $value === '' || $field === '') {
            return false;
        }

        foreach ($this->entries as $entry) {
            if (($entry[AuthorizationDetail::Type->value] ?? '') !== $type) {
                continue;
            }

            $values = $entry[$field] ?? [];
            if (!\is_array($values)) {
                continue;
            }
            if (!array_is_list($values)) {
                continue;
            }

            if (\in_array($value, $values, true)) {
                return true;
            }

            if ($wildcard !== null && \in_array($wildcard, $values, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Narrow $field of every entry to the values $resolver allows for its type.
     * A null result leaves the entry untouched; an empty result drops it. The
     * result only ever narrows: a value the entry did not already list is
     * discarded, so a resolver cannot widen the original grant. An entry that
     * lists $wildcard is the exception: the resolver's result is taken as the
     * expansion of the wildcard, as with grants().
     *
     * @param callable(string $type, list<string> $values): ?array<mixed> $resolver
     */
    public function restrict(string $field, callable $resolver, ?string $wildcard = null): self
    {
        $entries = [];
        foreach ($this->entries as $entry) {
            $type = $entry[AuthorizationDetail::Type->value] ?? '';
            if (!\is_string($type) || $type === '' || $field === '') {
                $entries[] = $entry;
                continue;
            }

            $values = $entry[$field] ?? [];
            $values = \is_array($values) && array_is_list($values)
                ? array_values(array_filter($values, \is_string(...)))
                : [];

            $allowed = $resolver($type, $values);
            if ($allowed === null) {
                $entries[] = $entry;
                continue;
            }

            $allowed = array_filter($allowed, \is_string(...));
            if ($wildcard === null || !\in_array($wildcard, $values, true)) {
                $allowed = array_intersect($values, $allowed);
            }
            $allowed = array_values($allowed);
            if ($allowed === []) {
                continue;
            }

            $entry[$field] = $allowed;
            $entries[] = $entry;
        }

        return new self($entries);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->entries;
    }
}
