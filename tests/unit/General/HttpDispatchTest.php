<?php

namespace Tests\Unit\General;

use PHPUnit\Framework\TestCase;

class HttpDispatchTest extends TestCase
{
    private function parseHostFromData(string $data): string
    {
        $lines = explode("\n", $data, 3);
        $domain = '';
        if (count($lines) > 1) {
            $hostParts = explode('Host: ', $lines[1] ?? '');
            $domain = isset($hostParts[1]) ? trim($hostParts[1]) : '';
        }
        return $domain;
    }

    // Core cases
    public function testParsesHostHeaderCorrectly(): void
    {
        $data = "GET /path HTTP/1.1\nHost: example.com\n";
        $this->assertEquals('example.com', $this->parseHostFromData($data));
    }

    public function testParsesHostHeaderWithPort(): void
    {
        $data = "GET /path HTTP/1.1\nHost: example.com:8080\n";
        $this->assertEquals('example.com:8080', $this->parseHostFromData($data));
    }

    // Edge cases
    public static function edgeCasesProvider(): array
    {
        return [
            'missing host header' => [
                "GET /path HTTP/1.1\nContent-Type: application/json",
                ''
            ],
            'single line request' => [
                "GET /path HTTP/1.1",
                ''
            ],
            'empty input' => [
                '',
                ''
            ],
            'host in wrong position' => [
                "GET /path HTTP/1.1\nContent-Type: json\nHost: example.com",
                ''
            ],
            'host with whitespace' => [
                "GET /path HTTP/1.1\nHost:   example.com   \n",
                'example.com'
            ],
        ];
    }

    /**
     * @dataProvider edgeCasesProvider
     */
    public function testHandlesEdgeCases(string $input, string $expected): void
    {
        $this->assertEquals($expected, $this->parseHostFromData($input));
    }
}