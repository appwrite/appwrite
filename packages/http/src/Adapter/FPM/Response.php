<?php

declare(strict_types=1);

namespace Utopia\Http\Adapter\FPM;

use Utopia\Http\Response as UtopiaResponse;

class Response extends UtopiaResponse
{
    /**
     * Write
     *
     * Send output
     *
     * @return bool False if write cannot complete, such as request ended by client
     */
    public function write(string $content): bool
    {
        echo $content;
        return true;
    }

    /**
     * End
     *
     * Send optional content and end
     */
    public function end(?string $content = null): void
    {
        if (!\is_null($content)) {
            echo $content;
        }
    }


    /**
     * Send Status Code
     */
    protected function sendStatus(int $statusCode): void
    {
        http_response_code($statusCode);
    }

    /**
     * Send Header
     *
     * Output Header. Header names are stored lowercased internally; they are
     * formatted to the conventional Title-Case form on the wire to match the
     * Swoole adapter (e.g. "content-type" => "Content-Type").
     *
     * @param  array<int, string>  $value
     */
    public function sendHeader(string $key, array $value): void
    {
        $key = ucwords(strtolower($key), '-');

        // First value replaces any header of the same name; the rest are
        // appended so multi-value headers (e.g. Set-Cookie) emit one line each.
        $replace = true;
        foreach ($value as $v) {
            header($key . ': ' . $v, $replace);
            $replace = false;
        }
    }

    /**
     * Send Cookie
     *
     * Output Cookie
     *
     * @param  array<string, mixed>  $options
     */
    protected function sendCookie(string $name, string $value, array $options): void
    {
        // Coalesce nulls to the types setcookie() expects for each option, and
        // map our 'expire' key to PHP's 'expires' keyword.
        setcookie($name, $value, [
            'expires' => $options['expire'] ?? 0,
            'path' => $options['path'] ?? '',
            'domain' => $options['domain'] ?? '',
            'secure' => $options['secure'] ?? false,
            'httponly' => $options['httponly'] ?? false,
            'samesite' => $options['samesite'] ?? '',
        ]);
    }
}
