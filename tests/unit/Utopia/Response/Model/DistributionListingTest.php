<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\DistributionListing;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class DistributionListingTest extends TestCase
{
    public function testScreenshotPathsAreRemoved(): void
    {
        $listing = (new DistributionListing())->filter(new Document([
            'screenshots' => [
                ['set' => 'phone', 'path' => 'listings/5e5ea5c16897e/1.png', 'size' => 204800, 'width' => 1080, 'height' => 1920, 'digest' => 'abc'],
                ['set' => 'tablet', 'path' => 'listings/5e5ea5c16897e/2.png', 'size' => 102400, 'width' => 2048, 'height' => 2732, 'digest' => 'def'],
            ],
        ]));

        $this->assertSame([
            ['set' => 'phone', 'size' => 204800, 'width' => 1080, 'height' => 1920, 'digest' => 'abc'],
            ['set' => 'tablet', 'size' => 102400, 'width' => 2048, 'height' => 2732, 'digest' => 'def'],
        ], $listing->getAttribute('screenshots'));
    }

    public function testStoredScreenshotPathsAreRemoved(): void
    {
        $listing = (new DistributionListing())->filter(new Document([
            'screenshots' => [
                ['$id' => 'first', 'set' => 'phoneScreenshots', 'path' => 'listings/5e5ea5c16897e/first.png', 'size' => 204800, 'width' => 1080, 'height' => 1920, 'digest' => 'abc', 'aiGeneratedState' => 'aiGeneratedStateNotAiGenerated'],
            ],
        ]));

        $this->assertSame([
            ['$id' => 'first', 'set' => 'phoneScreenshots', 'size' => 204800, 'width' => 1080, 'height' => 1920, 'digest' => 'abc', 'aiGeneratedState' => 'aiGeneratedStateNotAiGenerated'],
        ], $listing->getAttribute('screenshots'));
    }

    public function testMissingScreenshotsStayUnset(): void
    {
        $listing = (new DistributionListing())->filter(new Document([]));

        $this->assertFalse($listing->isSet('screenshots'));
    }

    public function testExampleHasNoScreenshotPath(): void
    {
        $example = (new DistributionListing())->getRules()['screenshots']['example'];

        $this->assertArrayNotHasKey('path', $example[0]);
    }
}
