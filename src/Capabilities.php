<?php

declare(strict_types=1);

namespace Utopia\SMTP;

/**
 * What the server advertised in its EHLO reply.
 *
 * Discarded and rebuilt after STARTTLS: RFC 3207 section 4.2 requires that
 * anything learned before the handshake is forgotten, since none of it was
 * authenticated.
 */
final readonly class Capabilities
{
    /**
     * @param  array<string, list<string>>  $keywords
     */
    private function __construct(private array $keywords) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * The first line of an EHLO reply is the greeting, not a keyword.
     */
    public static function fromReply(Reply $reply): self
    {
        $keywords = [];

        foreach (\array_slice($reply->lines, 1) as $line) {
            // Some servers write "AUTH=PLAIN LOGIN" where the grammar asks for
            // "AUTH PLAIN LOGIN", so both separators are accepted.
            if (preg_match('/^([A-Za-z0-9][A-Za-z0-9-]*)(?:[ =](.*))?$/', trim($line), $matches) !== 1) {
                continue;
            }

            $parameters = trim($matches[2] ?? '');

            $keywords[strtoupper($matches[1])] = $parameters === ''
                ? []
                : array_map(\strtoupper(...), preg_split('/\s+/', $parameters) ?: []);
        }

        return new self($keywords);
    }

    public function has(string $keyword): bool
    {
        return isset($this->keywords[strtoupper($keyword)]);
    }

    /**
     * @return list<string>
     */
    public function params(string $keyword): array
    {
        return $this->keywords[strtoupper($keyword)] ?? [];
    }

    /**
     * The SIZE extension of RFC 1870. A declared zero means the server sets no
     * fixed maximum, which is reported the same way as no declaration at all.
     */
    public function maxSize(): ?int
    {
        $size = $this->params('SIZE')[0] ?? null;

        if ($size === null || preg_match('/^\d+$/', $size) !== 1 || (int) $size === 0) {
            return null;
        }

        return (int) $size;
    }

    /**
     * @return list<string>
     */
    public function mechanisms(): array
    {
        return $this->params('AUTH');
    }
}
