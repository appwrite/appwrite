<?php

namespace Tests\Compat\Http;

use Swoole\Http\Cookie;
use Utopia\Http\Response;

/**
 * A Response adapter that records what the Swoole adapter would be asked to
 * send: status, headers (as `Swoole\Http\Response::header()` receives
 * them), cookies (as the `Set-Cookie` line Swoole renders) and body.
 */
final class Capture extends Response
{
    /** @var array<string, mixed> */
    public array $wire = ['status' => null, 'headers' => [], 'order' => [], 'cookies' => [], 'body' => '', 'writes' => 0, 'ended' => false];

    /**
     * The wire, with the decoded body when it was sent compressed.
     *
     * @return array<string, mixed>
     */
    public function out(): array
    {
        $wire = $this->wire;
        $encoding = $wire['headers']['content-encoding'][0] ?? null;
        $decoded = match ($encoding) {
            'gzip' => gzdecode($wire['body']),
            'deflate' => gzinflate($wire['body']),
            'br' => brotli_uncompress($wire['body']),
            'zstd' => zstd_uncompress($wire['body']),
            default => null,
        };
        if ($decoded !== null) {
            $wire['decoded'] = $decoded;
        }

        return $wire;
    }

    public function write(string $content): bool
    {
        if ($this->wire['ended']) {
            return false;
        }
        $this->wire['writes']++;
        $this->wire['body'] .= $content;

        return true;
    }

    public function end(?string $content = null): void
    {
        if ($this->wire['ended']) {
            return;
        }
        $this->wire['body'] .= $content ?? '';
        $this->wire['ended'] = true;
    }

    protected function sendStatus(int $statusCode): void
    {
        $this->wire['status'] = $statusCode;
    }

    /**
     * @param  array<int, string>  $value
     */
    public function sendHeader(string $key, array $value): void
    {
        if (!\array_key_exists($key, $this->wire['headers'])) {
            $this->wire['order'][] = $key;
        }
        $this->wire['headers'][$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function sendCookie(string $name, string $value, array $options): void
    {
        $cookie = (new Cookie())
            ->withName($name)
            ->withValue($value)
            ->withExpires($options['expire'] ?? 0)
            ->withPath($options['path'] ?? '')
            ->withDomain($options['domain'] ?? '')
            ->withSecure($options['secure'] ?? false)
            ->withHttpOnly($options['httponly'] ?? false)
            ->withSameSite($options['samesite'] ?? '');
        $this->wire['cookies'][] = @$cookie->toString();
    }
}
