<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Outcome;
use Utopia\SMTP\ProtocolException;
use Utopia\SMTP\Reply;

final class ReplyTest extends TestCase
{
    public function testReadsTheEnhancedStatusCode(): void
    {
        $this->assertSame('2.1.5', (new Reply(250, ['2.1.5 Recipient ok']))->status);
    }

    public function testLeavesTheStatusNullWhenThereIsNone(): void
    {
        $this->assertNull((new Reply(250, ['Recipient ok']))->status);
    }

    public function testDoesNotMistakeAVersionForAStatus(): void
    {
        $this->assertNull((new Reply(220, ['mail.example.test ESMTP Postfix 3.7.1']))->status);
    }

    public function testClassifiesByRange(): void
    {
        $this->assertSame(Outcome::Success, (new Reply(250, ['Ok']))->outcome);
        $this->assertSame(Outcome::Transient, (new Reply(451, ['Try later']))->outcome);
        $this->assertSame(Outcome::Permanent, (new Reply(550, ['No such user']))->outcome);
    }

    public function testNamesTheIntermediateReply(): void
    {
        // 354 is not a success and not a failure. Three booleans had no word
        // for it and reported false to all of them.
        $this->assertSame(Outcome::Intermediate, (new Reply(354, ['Go ahead']))->outcome);
    }

    public function testRejectsACodeThatIsNotAReply(): void
    {
        $this->expectException(ProtocolException::class);

        new Reply(600, ['Not a class RFC 5321 defines']);
    }

    public function testJoinsContinuationLines(): void
    {
        $this->assertSame('one two', (new Reply(250, ['one', 'two']))->text());
    }
}
