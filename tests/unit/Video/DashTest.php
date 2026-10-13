<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use Appwrite\Video\Dash;
use PHPUnit\Framework\TestCase;

final class DashTest extends TestCase
{
    public function testGroupsVideoRungsIntoOneSet(): void
    {
        $sets = Dash::group([
            $this->entry('video', bandwidth: 800000, width: 1280, baseUrl: '/r720/'),
            $this->entry('video', bandwidth: 400000, width: 640, baseUrl: '/r360/'),
        ]);

        $this->assertCount(1, $sets);
        $this->assertSame(0, $sets[0]['id']);
        $this->assertSame('video', $sets[0]['attributes']['contentType']);
        $this->assertSame('true', $sets[0]['attributes']['segmentAlignment']);
        $this->assertSame('1', $sets[0]['attributes']['startWithSAP']);
        $this->assertArrayNotHasKey('bitstreamSwitching', $sets[0]['attributes']);
        $this->assertCount(2, $sets[0]['representations']);
        $this->assertSame('400000', $sets[0]['representations'][0]['attributes']['bandwidth']);
        $this->assertSame('800000', $sets[0]['representations'][1]['attributes']['bandwidth']);
        $this->assertSame('/r360/', $sets[0]['representations'][0]['baseUrl']);
        $this->assertSame('/r720/', $sets[0]['representations'][1]['baseUrl']);
    }

    public function testKeepsAudioLanguagesInSeparateSets(): void
    {
        $sets = Dash::group([
            $this->entry('audio', bandwidth: 64000, lang: 'eng', baseUrl: '/a-en/'),
            $this->entry('audio', bandwidth: 64000, lang: 'fra', baseUrl: '/a-fr/'),
            $this->entry('video', bandwidth: 400000, width: 640, baseUrl: '/v/'),
        ]);

        $this->assertCount(3, $sets);

        $byType = [];
        foreach ($sets as $set) {
            $contentType = $set['attributes']['contentType'];
            $lang = $set['attributes']['lang'] ?? '';
            $byType[$contentType . ':' . $lang] = $set;
        }

        $this->assertArrayHasKey('video:', $byType);
        $this->assertArrayHasKey('audio:eng', $byType);
        $this->assertArrayHasKey('audio:fra', $byType);
        $this->assertCount(1, $byType['video:']['representations']);
        $this->assertCount(1, $byType['audio:eng']['representations']);
        $this->assertCount(1, $byType['audio:fra']['representations']);
        $this->assertSame('/a-en/', $byType['audio:eng']['representations'][0]['baseUrl']);
        $this->assertSame('/a-fr/', $byType['audio:fra']['representations'][0]['baseUrl']);
    }

    public function testGroupsSameLanguageAudioByBandwidth(): void
    {
        $sets = Dash::group([
            $this->entry('audio', bandwidth: 128000, lang: 'eng', baseUrl: '/a-hi/'),
            $this->entry('audio', bandwidth: 64000, lang: 'eng', baseUrl: '/a-lo/'),
        ]);

        $this->assertCount(1, $sets);
        $this->assertSame('audio', $sets[0]['attributes']['contentType']);
        $this->assertSame('eng', $sets[0]['attributes']['lang']);
        $this->assertCount(2, $sets[0]['representations']);
        $this->assertSame('64000', $sets[0]['representations'][0]['attributes']['bandwidth']);
        $this->assertSame('128000', $sets[0]['representations'][1]['attributes']['bandwidth']);
        $this->assertSame('/a-lo/', $sets[0]['representations'][0]['baseUrl']);
        $this->assertSame('/a-hi/', $sets[0]['representations'][1]['baseUrl']);
    }

    public function testDropsBitstreamSwitchingFromAttributes(): void
    {
        $entry = $this->entry('video', bandwidth: 400000, width: 640, baseUrl: '/r/');
        $entry['attributes']['bitstreamSwitching'] = 'true';

        $sets = Dash::group([$entry]);

        $this->assertArrayNotHasKey('bitstreamSwitching', $sets[0]['attributes']);
        $this->assertSame('true', $sets[0]['attributes']['segmentAlignment']);
        $this->assertSame('1', $sets[0]['attributes']['startWithSAP']);
    }

    /**
     * @return array{
     *     attributes: array<string, string>,
     *     representation: array{attributes: array<string, string>, segmentList: array<string, mixed>},
     *     baseUrl: string
     * }
     */
    private function entry(
        string $contentType,
        int $bandwidth,
        string $baseUrl,
        ?int $width = null,
        ?string $lang = null
    ): array {
        $attributes = ['contentType' => $contentType];
        if ($lang !== null) {
            $attributes['lang'] = $lang;
        }

        $representationAttributes = [
            'id' => $contentType . '-' . $bandwidth,
            'bandwidth' => (string) $bandwidth,
        ];
        if ($width !== null) {
            $representationAttributes['width'] = (string) $width;
        }

        return [
            'attributes' => $attributes,
            'representation' => [
                'attributes' => $representationAttributes,
                'segmentList' => [
                    'attributes' => ['timescale' => '1000000', 'duration' => '4000000'],
                    'init' => 'init.mp4',
                    'media' => ['seg-0.m4s'],
                ],
            ],
            'baseUrl' => $baseUrl,
        ];
    }
}
