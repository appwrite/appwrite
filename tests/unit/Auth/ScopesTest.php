<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Appwrite\Auth\Scopes;
use PHPUnit\Framework\TestCase;

final class ScopesTest extends TestCase
{
    public function testHoldsOnlyWhatWasGranted(): void
    {
        $scopes = (new Scopes())->grant(['functions.write', 'functions.read']);

        $this->assertTrue($scopes->holds('functions.write'));
        $this->assertFalse($scopes->holds('users.write'));
        $this->assertFalse($scopes->holds(''));
    }

    public function testMissingKeepsRequestOrderAndDropsHeld(): void
    {
        $scopes = (new Scopes())->grant(['functions.write'])->grant(['functions.read']);

        $this->assertSame(
            ['users.write', 'databases.write'],
            $scopes->missing(['users.write', 'functions.read', 'databases.write', 'functions.write'])
        );
        $this->assertSame([], $scopes->missing(['functions.read']));
        $this->assertSame([], $scopes->missing([]));
    }

    public function testNothingGrantedHoldsNothing(): void
    {
        $this->assertSame(['functions.read'], (new Scopes())->missing(['functions.read']));
    }
}
