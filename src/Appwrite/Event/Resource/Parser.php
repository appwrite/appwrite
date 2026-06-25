<?php

namespace Appwrite\Event\Resource;

use Appwrite\Extend\Exception;
use Utopia\Database\Document;

class Parser
{
    /**
     * Substitute `{namespace.key}` placeholders inside a label using the
     * supplied context. Supported namespaces map to the keys of `$context`;
     * an unknown namespace falls back to the `response` payload to preserve
     * the historical behaviour of `audits.resource` rendering.
     *
     * @param array<string, array<string, mixed>|Document|object> $context
     */
    public static function render(string $template, array $context): string
    {
        if ($template === '' || !str_contains($template, '{')) {
            return $template;
        }

        $fallback = self::asArray($context['response'] ?? []);

        preg_match_all('/{([^}]+)}/', $template, $matches);
        foreach ($matches[1] as $pos => $match) {
            $find = $matches[0][$pos];
            $parts = explode('.', $match, 2);
            if (count($parts) !== 2) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, "Invalid resource label template: {$template}");
            }

            [$namespace, $key] = $parts;
            $bag = array_key_exists($namespace, $context) ? self::asArray($context[$namespace]) : $fallback;
            if (!array_key_exists($key, $bag)) {
                continue;
            }

            $value = $bag[$key];
            if (!is_string($value)) {
                if (is_array($value)) {
                    $value = json_encode($value);
                } elseif (is_object($value) && method_exists($value, '__toString')) {
                    $value = (string) $value;
                } elseif (is_scalar($value)) {
                    $value = (string) $value;
                } else {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, "Cannot stringify label value: {$template}");
                }
            }

            $template = str_replace($find, $value, $template);
        }

        return $template;
    }

    /**
     * Split a rendered resource string into ordered {type, id} segments.
     * Trailing odd segment (e.g. `bucket/{id}/files`) is treated as a
     * collection name with no id and dropped.
     *
     * @return array<int, array{type: string, id: string}>
     */
    public static function parse(string $rendered): array
    {
        if ($rendered === '') {
            return [];
        }

        $parts = explode('/', trim($rendered, '/'));
        $segments = [];
        for ($i = 0, $n = count($parts); $i + 1 < $n; $i += 2) {
            $segments[] = ['type' => $parts[$i], 'id' => $parts[$i + 1]];
        }
        return $segments;
    }

    /**
     * Apply the usage-warehouse granularity rule on top of {@see parse()}:
     *  - databases stop at `database/{databaseId}/table` + tableId
     *  - storage stops at `bucket` + bucketId
     *  - functions/sites are scalar (`function`/`site` + id)
     *  - everything else returns the head segment unchanged
     *
     * @param array<int, array{type: string, id: string}> $segments
     * @return array{resource: string, resourceId: string}
     */
    public static function capForUsage(array $segments): array
    {
        if ($segments === []) {
            return ['resource' => '', 'resourceId' => ''];
        }

        $head = $segments[0];
        if ($head['type'] === 'database' && isset($segments[1]) && $segments[1]['type'] === 'collection') {
            return [
                'resource' => 'database/' . $head['id'] . '/table',
                'resourceId' => $segments[1]['id'],
            ];
        }

        return ['resource' => $head['type'], 'resourceId' => $head['id']];
    }

    /**
     * @return array<string, mixed>
     */
    private static function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof Document) {
            return $value->getArrayCopy();
        }
        if (is_object($value)) {
            return (array) $value;
        }
        return [];
    }
}
