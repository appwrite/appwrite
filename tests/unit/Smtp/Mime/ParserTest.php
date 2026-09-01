<?php

declare(strict_types=1);

namespace Tests\Unit\Smtp\Mime;

use Appwrite\Smtp\Mime\Parser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function test_parses_alternatives_and_attachment(): void
    {
        $message = (new Parser(1024 * 1024))->parse(<<<'EMAIL'
From: Sender <sender@example.net>
To: Support <support@example.com>
Subject: =?UTF-8?Q?Help_=E2=9C=93?=
Message-ID: <message@example.net>
Content-Type: multipart/mixed; boundary="outer"

--outer
Content-Type: multipart/alternative; boundary="inner"

--inner
Content-Type: text/plain; charset=UTF-8

Plain body
--inner
Content-Type: text/html; charset=UTF-8

<p>HTML body</p>
--inner--
--outer
Content-Type: application/pdf; name="invoice.pdf"
Content-Disposition: attachment; filename="invoice.pdf"
Content-Transfer-Encoding: base64

UERG
--outer--
EMAIL);

        $this->assertSame('Help ✓', $message->subject);
        $this->assertSame('Plain body', trim($message->text));
        $this->assertSame('<p>HTML body</p>', trim($message->html));
        $this->assertCount(1, $message->attachments);
        $this->assertSame('invoice.pdf', $message->attachments[0]->filename);
        $this->assertSame('PDF', $message->attachments[0]->content);
    }

    public function test_rejects_invalid_base64(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Parser(1024))->parse(<<<'EMAIL'
Content-Type: application/octet-stream
Content-Transfer-Encoding: base64

not-valid-***
EMAIL);
    }

    public function test_enforces_decoded_content_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Parser(3))->parse("Content-Type: text/plain\r\n\r\nfour");
    }

    public function test_rejects_multipart_without_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Parser(1024))->parse("Content-Type: multipart/mixed\r\n\r\nbody");
    }
}
