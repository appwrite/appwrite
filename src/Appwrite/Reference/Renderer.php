<?php

namespace Appwrite\Reference;

use Appwrite\Extend\Exception;
use Utopia\Database\Document;

/**
 * Renders templated resource references — e.g. `database/{request.databaseId}`
 * — by substituting `{namespace.key}` placeholders from the per-request
 * context (request params, response payload, user, project, …). One renderer
 * is bound to one context and reused across the labels that need it.
 */
class Renderer
{
    /** @var array<string, array<string, mixed>> */
    private array $context;

    /**
     * @param array<string, array<string, mixed>|Document|object> $context
     */
    public function __construct(array $context)
    {
        $this->context = [];
        foreach ($context as $namespace => $bag) {
            $this->context[$namespace] = self::asArray($bag);
        }
    }

    public function render(string $template): string
    {
        if ($template === '' || !str_contains($template, '{')) {
            return $template;
        }

        $fallback = $this->context['response'] ?? [];

        preg_match_all('/{([^}]+)}/', $template, $matches);
        foreach ($matches[1] as $pos => $match) {
            $find = $matches[0][$pos];
            $parts = explode('.', $match, 2);
            if (count($parts) !== 2) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, "Invalid resource reference template: {$template}");
            }

            [$namespace, $key] = $parts;
            $bag = $this->context[$namespace] ?? $fallback;
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
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, "Cannot stringify reference value: {$template}");
                }
            }

            $template = str_replace($find, $value, $template);
        }

        return $template;
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
