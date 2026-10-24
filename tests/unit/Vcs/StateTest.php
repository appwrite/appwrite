<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Vcs\State;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateTest extends TestCase
{
    private const string KEY = 'signing-key';

    public function testVerifiesSignedState(): void
    {
        $state = State::create('project', 'user', 'https://console.test/success', 'https://console.test/failure');

        $verified = State::verify((string) \json_encode($state->sign(self::KEY)), self::KEY);

        $this->assertInstanceOf(State::class, $verified);
        $this->assertSame('project', $verified->projectId);
        $this->assertSame('user', $verified->userId);
        $this->assertSame('https://console.test/success', $verified->success);
        $this->assertSame('https://console.test/failure', $verified->failure);
        $this->assertFalse($verified->isExpired());
    }

    public function testCreatesUniqueNonces(): void
    {
        $first = State::create('project', 'user', '', '');
        $second = State::create('project', 'user', '', '');

        $this->assertNotSame($first->nonce, $second->nonce);
        $this->assertNotSame($first->sign(self::KEY)['signature'], $second->sign(self::KEY)['signature']);
    }

    public function testRejectsOtherKey(): void
    {
        $state = State::create('project', 'user', '', '')->sign(self::KEY);

        $this->assertNotInstanceOf(\Appwrite\Vcs\State::class, State::verify((string) \json_encode($state), 'other-key'));
    }

    /**
     * @return \Iterator<string, array{string, mixed}>
     */
    public static function tamperedFields(): \Iterator
    {
        yield 'project' => ['projectId', 'other'];
        yield 'user' => ['userId', 'other'];
        yield 'success' => ['success', 'https://other.test'];
        yield 'failure' => ['failure', 'https://other.test'];
        yield 'expire' => ['expire', \PHP_INT_MAX];
        yield 'nonce' => ['nonce', 'other'];
        yield 'signature' => ['signature', 'other'];
        yield 'non-string signature' => ['signature', 1234];
        yield 'non-integer expire' => ['expire', '1'];
    }

    #[DataProvider('tamperedFields')]
    public function testRejectsTamperedField(string $field, mixed $value): void
    {
        $state = State::create('project', 'user', '', '')->sign(self::KEY);
        $state[$field] = $value;

        $this->assertNotInstanceOf(\Appwrite\Vcs\State::class, State::verify((string) \json_encode($state), self::KEY));
    }

    public function testRejectsMissingField(): void
    {
        $state = State::create('project', 'user', '', '')->sign(self::KEY);
        unset($state['userId']);

        $this->assertNotInstanceOf(\Appwrite\Vcs\State::class, State::verify((string) \json_encode($state), self::KEY));
    }

    public function testRejectsMalformedState(): void
    {
        $this->assertNotInstanceOf(\Appwrite\Vcs\State::class, State::verify('not-json', self::KEY));
        $this->assertNotInstanceOf(\Appwrite\Vcs\State::class, State::verify('"string"', self::KEY));
    }

    public function testDetectsExpiredState(): void
    {
        $state = new State('project', 'user', '', '', \time() - 1, 'nonce');

        $verified = State::verify((string) \json_encode($state->sign(self::KEY)), self::KEY);

        $this->assertInstanceOf(State::class, $verified);
        $this->assertTrue($verified->isExpired());
    }
}
