<?php

declare(strict_types=1);

namespace Utopia\Domains\Tests\Registrar\Adapter;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Domains\Registrar\Adapter\OpenSRS;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class OpenSRSTest extends TestCase
{
    public const string RESPONSE = <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="no"?>
        <OPS_envelope><body><data_block><dt_assoc>
        <item key="response_code">200</item>
        <item key="is_success">1</item>
        <item key="attributes"><dt_assoc><item key="id">order-1</item></dt_assoc></item>
        </dt_assoc></data_block></body></OPS_envelope>
        XML;

    public function testEnvelopeCarriesValuesAsText(): void
    {
        /** @var ArrayObject<int, RequestInterface> $requests */
        $requests = new ArrayObject();
        $adapter = new OpenSRS('key', 'user', 'pass', client: $this->client($requests));
        $authCode = "a&b<c>'d\"</item><item key=\"reg_type\">new";

        $this->assertSame('order-1', $adapter->transfer('example.com', $authCode));

        $this->assertCount(1, $requests);
        $request = $requests[0] ?? null;
        $this->assertInstanceOf(RequestInterface::class, $request);
        $envelope = \simplexml_load_string($request->getBody()->__toString());
        $this->assertNotFalse($envelope);

        $this->assertSame([$authCode], $this->texts($envelope, 'auth_info'));
        $this->assertSame(['transfer'], $this->texts($envelope, 'reg_type'));
    }

    /**
     * @return list<string>
     */
    private function texts(\SimpleXMLElement $envelope, string $key): array
    {
        $items = $envelope->xpath('//item[@key="attributes"]/dt_assoc/item[@key="' . $key . '"]') ?: [];

        return \array_values(\array_map(static fn (\SimpleXMLElement $item): string => $item->__toString(), $items));
    }

    /**
     * @param  ArrayObject<int, RequestInterface>  $requests
     */
    private function client(ArrayObject $requests): ClientInterface
    {
        return new readonly class ($requests) implements ClientInterface {
            /**
             * @param  ArrayObject<int, RequestInterface>  $requests
             */
            public function __construct(
                private ArrayObject $requests,
            ) {
            }

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;

                return new Response(200, body: new Stream(OpenSRSTest::RESPONSE));
            }
        };
    }
}
