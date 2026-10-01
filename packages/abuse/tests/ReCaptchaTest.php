<?php

namespace Utopia\Abuse\Tests;

use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Abuse\ReCaptcha;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class ReCaptchaTest extends TestCase
{
    /**
     * @var ArrayObject<int, RequestInterface>
     */
    private ArrayObject $requests;

    #[\Override]
    protected function setUp(): void
    {
        $this->requests = new ArrayObject();
    }

    public function testSendsFormToSiteVerify(): void
    {
        $client = $this->client('{"success": true, "score": 0.9}');

        $this->assertSame(true, new ReCaptcha('s3cret&key', $client)->verify('token/with+chars', '203.0.113.7'));

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(ReCaptcha::URL, (string) $request->getUri());
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertSame('secret=s3cret%26key&response=token%2Fwith%2Bchars&remoteip=203.0.113.7', (string) $request->getBody());
    }

    /**
     * @return array<string, array{0: string, 1: float, 2: bool}>
     */
    public static function verdicts(): array
    {
        return [
            'above threshold' => ['{"success": true, "score": 0.9}', 0.5, true],
            'at threshold' => ['{"success": true, "score": 0.5}', 0.5, true],
            'below threshold' => ['{"success": true, "score": 0.4}', 0.5, false],
            'custom threshold' => ['{"success": true, "score": 0.7}', 0.8, false],
            'integer score' => ['{"success": true, "score": 1}', 0.5, true],
            'unsuccessful' => ['{"success": false, "score": 0.9}', 0.5, false],
            'truthy success' => ['{"success": 1, "score": 0.9}', 0.5, false],
            'missing score' => ['{"success": true}', 0.5, false],
            'non-numeric score' => ['{"success": true, "score": "0.9"}', 0.5, false],
            'error codes' => ['{"success": false, "error-codes": ["invalid-input-secret"]}', 0.5, false],
            'invalid json' => ['<html></html>', 0.5, false],
            'empty body' => ['', 0.0, false],
        ];
    }

    #[DataProvider('verdicts')]
    public function testVerdict(string $body, float $score, bool $expected): void
    {
        $this->assertSame($expected, new ReCaptcha('secret', $this->client($body))->verify('token', '127.0.0.1', $score));
    }

    private function client(string $body): ClientInterface
    {
        return new readonly class ($body, $this->requests) implements ClientInterface {
            /**
             * @param  ArrayObject<int, RequestInterface>  $requests
             */
            public function __construct(
                private string $body,
                private ArrayObject $requests,
            ) {
            }

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;

                return new Response(200, body: new Stream($this->body));
            }
        };
    }
}
