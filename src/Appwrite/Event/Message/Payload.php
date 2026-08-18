<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

/**
 * Hydrate queue payload fields that Redis JSON-decodes as arrays but the
 * Inline adapter delivers as live {@see Document} objects.
 */
final class Payload
{
    public static function document(mixed $value): Document
    {
        if ($value instanceof Document) {
            return $value;
        }

        return new Document(\is_array($value) ? $value : []);
    }

    public static function documentOrNull(mixed $value): ?Document
    {
        if ($value instanceof Document) {
            return $value;
        }

        if (empty($value) || !\is_array($value)) {
            return null;
        }

        return new Document($value);
    }

    /**
     * JSON-round-trip so Inline matches Redis: empty objects become [].
     *
     * @return array<mixed>
     */
    public static function jsonArray(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        $decoded = \json_decode(\json_encode($value), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
