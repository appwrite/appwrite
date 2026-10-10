<?php

namespace Tests\Compat\Http;

use Tests\Compat\Fault;
use Utopia\Http\Request;
use Utopia\Http\Response;
use Utopia\Http\Route;

/**
 * Getter calls by name (`["header_line", "x-a", "default"]`), the same on
 * both runtimes.
 */
final class Getters
{
    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private static function split(mixed $getter): array
    {
        if (\is_string($getter)) {
            return [$getter, []];
        }
        if (\is_array($getter) && $getter !== [] && array_is_list($getter)) {
            return [(string) $getter[0], \array_slice($getter, 1)];
        }
        throw new Fault('getter: ' . json_encode($getter));
    }

    public static function request(Request $r, mixed $getter): mixed
    {
        [$name, $a] = self::split($getter);
        if ($a === [] && \in_array($name, ['query', 'payload', 'param', 'cookie', 'header', 'header_line', 'has_header', 'files', 'server'], true)) {
            throw new Fault("getter {$name} needs a key");
        }

        return match ($name) {
            'method' => $r->getMethod(),
            'uri' => $r->getURI(),
            'protocol' => $r->getProtocol(),
            'hostname' => $r->getHostname(),
            'port' => $r->getPort(),
            'ip' => $r->getIP(),
            'raw_payload' => $r->getRawPayload(),
            'size' => $r->getSize(),
            'params' => $r->getParams(),
            'headers' => $r->getHeaders(),
            'cookies' => $r->getCookieParams(),
            'referer' => $r->getReferer(...$a),
            'origin' => $r->getOrigin(...$a),
            'user_agent' => $r->getUserAgent(...$a),
            'accept' => $r->getAccept(...$a),
            'query' => $r->getQuery(...$a),
            'payload' => $r->getPayload(...$a),
            'param' => $r->getParam(...$a),
            'cookie' => $r->getCookie(...$a),
            'header' => $r->getHeader(...$a),
            'header_line' => $r->getHeaderLine(...$a),
            'has_header' => $r->hasHeader(...$a),
            'files' => self::contents($r->getFiles(...$a)),
            'server' => $r->getServer(...$a),
            'content_range_start' => $r->getContentRangeStart(),
            'content_range_end' => $r->getContentRangeEnd(),
            'content_range_size' => $r->getContentRangeSize(),
            'content_range_unit' => $r->getContentRangeUnit(),
            'range_start' => $r->getRangeStart(),
            'range_end' => $r->getRangeEnd(),
            'range_unit' => $r->getRangeUnit(),
            default => throw new Fault("unknown request getter {$name}"),
        };
    }

    /**
     * Uploaded files with each `tmp_name` replaced by the file's content.
     *
     * @param array<mixed> $files
     * @return array<mixed>
     */
    private static function contents(array $files, bool $tmp = false): array
    {
        foreach ($files as $key => $value) {
            $isTmp = $tmp || $key === 'tmp_name';
            if (\is_array($value)) {
                $files[$key] = self::contents($value, $isTmp);
            } elseif ($isTmp && \is_string($value)) {
                $files[$key] = $value === '' ? '' : (string) file_get_contents($value);
            }
        }

        return $files;
    }

    public static function response(Response $r, mixed $getter): mixed
    {
        [$name, $a] = self::split($getter);

        return match ($name) {
            'status' => $r->getStatusCode(),
            'content_type' => $r->getContentType(),
            'is_sent' => $r->isSent(),
            'size' => $r->getSize(),
            'headers' => $r->getHeaders(),
            'header' => $r->getHeader(...$a),
            'header_line' => $r->getHeaderLine(...$a),
            'has_header' => $r->hasHeader(...$a),
            'cookies' => $r->getCookies(),
            default => throw new Fault("unknown response getter {$name}"),
        };
    }

    public static function route(mixed $route, mixed $getter): mixed
    {
        if (!$route instanceof Route) {
            return null;
        }
        [$name, $a] = self::split($getter);

        return match ($name) {
            'path' => $route->getPath(),
            'method' => @$route->getMethod(),
            'methods' => $route->getMethods(),
            'groups' => $route->getGroups(),
            'desc' => $route->getDesc(),
            'hook' => $route->getHook(),
            'label' => $route->getLabel($a[0], $a[1] ?? null),
            'params' => array_keys($route->getParams()),
            'injections' => array_keys($route->getInjections()),
            'order' => $route->getOrder() > 0,
            default => throw new Fault("unknown route getter {$name}"),
        };
    }
}
