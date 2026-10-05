<?php

declare(strict_types=1);

namespace Utopia\SMTP;

/**
 * One server response: a three digit code, the text of each line, and the
 * enhanced status code of RFC 3463 when the server advertises RFC 2034.
 */
final readonly class Reply implements \Stringable
{
    public ?string $status;

    public Outcome $outcome;

    /**
     * @param  list<string>  $lines  Line text, with the code and separator stripped.
     */
    public function __construct(
        public int $code,
        public array $lines,
    ) {
        $this->outcome = Outcome::fromCode($code);
        $this->status = preg_match('/^([245]\.\d{1,3}\.\d{1,3})(?: |$)/', $lines[0] ?? '', $matches) === 1
            ? $matches[1]
            : null;
    }

    public function text(): string
    {
        return implode(' ', $this->lines);
    }

    public function __toString(): string
    {
        return $this->code . ' ' . $this->text();
    }
}
