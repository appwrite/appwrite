<?php

declare(strict_types=1);

namespace Utopia\SMTP;

use Utopia\SMTP\Mime\Encoding;
use Utopia\SMTP\Mime\Header;

/**
 * A mailbox: an address with an optional display name.
 */
final readonly class Address implements \Stringable
{
    /** RFC 5321 section 4.5.3.1.1. */
    private const int MAX_LOCAL = 64;

    /** RFC 5321 section 4.5.3.1.2. */
    private const int MAX_DOMAIN = 255;

    public function __construct(
        public string $email,
        public string $name = '',
    ) {
        if (preg_match('/[\r\n\x00]/', $email . $name) === 1) {
            throw new \InvalidArgumentException('An address must not span lines');
        }

        $at = strrpos($email, '@');

        if (\in_array($at, [false, 0, \strlen($email) - 1], true)) {
            throw new \InvalidArgumentException("Not an address: {$email}");
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        if (\strlen($local) > self::MAX_LOCAL || \strlen($domain) > self::MAX_DOMAIN) {
            throw new \InvalidArgumentException("Address is over the length limits of RFC 5321: {$email}");
        }

        // filter_var refuses every non-ASCII local part, which RFC 6531 allows,
        // so it only gets to judge the addresses it understands.
        if (Encoding::isAscii($email) && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("Not an address: {$email}");
        }
    }

    /**
     * Whether sending this address needs the SMTPUTF8 extension of RFC 6531.
     */
    public function isInternational(): bool
    {
        return ! Encoding::isAscii($this->email);
    }

    /**
     * The header form, with the display name encoded when it is not plain ASCII.
     */
    public function __toString(): string
    {
        if ($this->name === '') {
            return "<{$this->email}>";
        }

        return Header::phrase($this->name) . " <{$this->email}>";
    }
}
