<?php

namespace Utopia\Tests\E2E\Sources;

use PHPUnit\Framework\TestCase;
use Utopia\Migration\Destination;
use Utopia\Migration\Resource;
use Utopia\Migration\Source;
use Utopia\Migration\Transfer;
use Utopia\Tests\Unit\Adapters\MockDestination;

abstract class Base extends TestCase
{
    protected ?Transfer $transfer = null;

    protected ?Source $source = null;

    protected ?Destination $destination = null;

    protected function setUp(): void
    {
        if (! $this->source) {
            throw new \Exception('Source not set');
        }

        $this->destination = new MockDestination();
        $this->transfer = new Transfer($this->source, $this->destination);
    }

    public function testGetName(): void
    {
        $this->assertNotEmpty($this->source::getName());
    }

    public function testGetSupportedResources(): void
    {
        $this->assertNotEmpty($this->source->getSupportedResources());

        foreach ($this->source->getSupportedResources() as $resource) {
            $this->assertContains($resource, Resource::ALL_RESOURCES);
        }
    }

    public function testCache(): void
    {
        $cache = $this->createMock(\Utopia\Migration\Cache::class);
        $this->source->registerCache($cache);

        $this->assertNotNull($this->source->cache);
    }

    /**
     * Call
     *
     * Make an API call
     *
     * @throws \Exception
     */
    protected function call(string $method, string $path = '', array $headers = [], array $params = []): array|string
    {
        $queryString = '';
        if ($method == 'GET' && ! empty($params)) {
            $queryString = '?'.http_build_query($params);
        }

        $url = $path.$queryString;

        $ch = curl_init($url);

        $responseHeaders = [];
        $responseStatus = -1;
        $responseType = '';
        $responseBody = '';

        switch ($headers['Content-Type']) {
            case 'application/json':
                $query = json_encode($params);
                break;

            default:
                $query = http_build_query($params);
                break;
        }

        foreach ($headers as $i => $header) {
            $headers[] = $i.':'.$header;
            unset($headers[$i]);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, php_uname('s').'-'.php_uname('r').':php-'.phpversion());
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', strtolower($header), 2);

            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });

        if ($method != 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }

        $responseBody = curl_exec($ch);

        $responseType = $responseHeaders['Content-Type'] ?? $responseHeaders['content-type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        switch (substr($responseType, 0, strpos($responseType, ';'))) {
            case 'application/json':
                $responseBody = json_decode($responseBody, true);
                break;
        }

        if (curl_errno($ch)) {
            throw new \Exception(curl_error($ch));
        }


        if ($responseStatus >= 400) {
            if (is_array($responseBody)) {
                throw new \Exception(json_encode($responseBody));
            } else {
                throw new \Exception($responseStatus.': '.$responseBody);
            }
        }

        return $responseBody;
    }
}
