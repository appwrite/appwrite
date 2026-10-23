<?php

declare(strict_types=1);

namespace Tests\Unit\SMTP;

use Appwrite\SMTP\Refusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefusalTest extends TestCase
{
    /**
     * @return \Iterator<string, array{bool, array<string, mixed>, bool}>
     */
    public static function results(): \Iterator
    {
        yield 'project smtp refuses the login' => [true, ['error' => '535 Authentication failed.', 'permanent' => true, 'code' => 535], true];
        yield 'project smtp refuses the recipient' => [true, ['error' => '550 No such user.', 'permanent' => true, 'code' => 550], true];
        yield 'project smtp refuses at the greeting' => [true, ['error' => '554 Go away.', 'permanent' => true, 'code' => 554], true];
        yield 'project smtp asks to try later' => [true, ['error' => '421 Try again later.', 'permanent' => false, 'code' => 421], false];
        yield 'project smtp cannot be reached' => [true, ['error' => 'Cannot reach smtp.example.test', 'permanent' => false, 'code' => 0], false];
        yield 'project smtp result without a classification' => [true, ['error' => 'Unknown error'], false];
        yield 'appwrite provider refuses the login' => [false, ['error' => '535 Authentication failed.', 'permanent' => true, 'code' => 535], false];
        yield 'appwrite provider refuses the recipient' => [false, ['error' => '550 No such user.', 'permanent' => true, 'code' => 550], false];
        yield 'appwrite provider asks to try later' => [false, ['error' => '421 Try again later.', 'permanent' => false, 'code' => 421], false];
    }

    /**
     * @param array<string, mixed> $result
     */
    #[DataProvider('results')]
    public function testOnlyAProjectsOwnServerRefusingForGoodIsFinal(bool $projectSmtp, array $result, bool $final): void
    {
        $this->assertSame($final, Refusal::isFinal($projectSmtp, $result));
    }
}
