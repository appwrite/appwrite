<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Support;

/**
 * Plays the services behind a FakeTransport: tracks the client's SUBs off the
 * wire and answers every PUB that carries a reply subject, so a test can drive
 * request-reply through the public API alone. Nothing written into a socket the
 * server has closed reaches it, so a request lost that way stays unanswered.
 */
final class Responder
{
    /** @var array<string, string> subscription subject keyed by sid */
    private array $subscriptions = [];

    /** @param \Closure(string $subject, string $payload): ?string $handler the reply payload, or null for none */
    public function __construct(private readonly FakeTransport $transport, private readonly \Closure $handler)
    {
        $transport->onWrite = $this->receive(...);
    }

    private function receive(string $wire): void
    {
        $offset = 0;
        while (($end = strpos($wire, "\r\n", $offset)) !== false) {
            $parts = explode(' ', substr($wire, $offset, $end - $offset));
            $offset = $end + 2;

            switch ($parts[0]) {
                case 'SUB':
                    $this->subscriptions[$parts[array_key_last($parts)]] = $parts[1];
                    break;
                case 'UNSUB':
                    unset($this->subscriptions[$parts[1]]);
                    break;
                case 'PUB':
                case 'HPUB':
                    $size = (int) $parts[array_key_last($parts)];
                    $headerSize = $parts[0] === 'HPUB' ? (int) $parts[\count($parts) - 2] : 0;
                    $reply = \count($parts) === ($parts[0] === 'HPUB' ? 5 : 4) ? $parts[2] : null;
                    $payload = substr($wire, $offset + $headerSize, $size - $headerSize);
                    $offset += $size + 2;
                    $this->answer($parts[1], $payload, $reply);
                    break;
            }
        }
    }

    private function answer(string $subject, string $payload, ?string $reply): void
    {
        if ($reply === null) {
            return;
        }

        $data = ($this->handler)($subject, $payload);
        $sid = $this->sidFor($reply);
        if ($data === null || $sid === null) {
            return;
        }

        $this->transport->pushInbound('MSG ' . $reply . ' ' . $sid . ' ' . \strlen($data) . "\r\n" . $data . "\r\n");
    }

    private function sidFor(string $subject): ?string
    {
        foreach ($this->subscriptions as $sid => $pattern) {
            if (self::matches($pattern, $subject)) {
                return (string) $sid;
            }
        }

        return null;
    }

    private static function matches(string $pattern, string $subject): bool
    {
        $patternTokens = explode('.', $pattern);
        $subjectTokens = explode('.', $subject);
        if (\count($patternTokens) !== \count($subjectTokens)) {
            return false;
        }

        foreach ($patternTokens as $index => $token) {
            if ($token !== '*' && $token !== $subjectTokens[$index]) {
                return false;
            }
        }

        return true;
    }
}
