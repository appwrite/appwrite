<?php

namespace Utopia\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use Utopia\Logger\Log;
use Utopia\Logger\Log\Breadcrumb;

class BreadcrumbTest extends TestCase
{
    /**
     * @throws \Throwable
     */
    public function testLogBreadcrumb(): void
    {
        $timestamp = \microtime(true);
        $breadcrumb = new Breadcrumb(Log::TYPE_DEBUG, 'http', 'POST /user', $timestamp);

        self::assertEquals(Log::TYPE_DEBUG, $breadcrumb->getType());
        self::assertEquals('http', $breadcrumb->getCategory());
        self::assertEquals('POST /user', $breadcrumb->getMessage());
        self::assertEquals($timestamp, $breadcrumb->getTimestamp());

        $breadcrumb = new Breadcrumb(Log::TYPE_INFO, 'http', 'POST /user', $timestamp);
        self::assertEquals(Log::TYPE_INFO, $breadcrumb->getType());
        $breadcrumb = new Breadcrumb(Log::TYPE_VERBOSE, 'http', 'POST /user', $timestamp);
        self::assertEquals(Log::TYPE_VERBOSE, $breadcrumb->getType());
        $breadcrumb = new Breadcrumb(Log::TYPE_ERROR, 'http', 'POST /user', $timestamp);
        self::assertEquals(Log::TYPE_ERROR, $breadcrumb->getType());
        $breadcrumb = new Breadcrumb(Log::TYPE_WARNING, 'http', 'POST /user', $timestamp);
        self::assertEquals(Log::TYPE_WARNING, $breadcrumb->getType());

        // Assert FAILS
        self::expectException(\ArgumentCountError::class);
        $breadcrumb = new Breadcrumb();  // @phpstan-ignore-line
        $breadcrumb = new Breadcrumb(Log::TYPE_DEBUG);  // @phpstan-ignore-line
        $breadcrumb = new Breadcrumb(Log::TYPE_DEBUG, 'http');  // @phpstan-ignore-line
        $breadcrumb = new Breadcrumb(Log::TYPE_DEBUG, 'http', 'POST /user');  // @phpstan-ignore-line
    }
}
