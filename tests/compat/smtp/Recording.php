<?php

namespace Tests\Compat\Smtp;

use Utopia\SMTP\Tests\Support\FakeTransport;

require_once __DIR__ . '/../../../packages/smtp/tests/Support/FakeTransport.php';

/**
 * The library's own scripted server (packages/smtp/tests/Support/FakeTransport.php),
 * also keeping the deadline the client gave each call, as TimeoutsTest's
 * subclass does. Its twin is `Fake` in crates/tools/compat/src/libs/smtp.rs.
 */
class Recording extends FakeTransport
{
    /** @var list<array{0: string, 1: float}> */
    public array $waits = [];

    public function connect(float $timeout, bool $tls): void
    {
        $this->waits[] = ['connect', $timeout];

        parent::connect($timeout, $tls);
    }

    public function read(int $length, float $timeout): string
    {
        $this->waits[] = ['read', $timeout];

        return parent::read($length, $timeout);
    }

    public function write(string $data, float $timeout): void
    {
        $this->waits[] = ['write', $timeout];

        parent::write($data, $timeout);
    }
}
