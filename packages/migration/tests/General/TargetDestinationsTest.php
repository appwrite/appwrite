<?php

namespace Utopia\Migration\Tests\General;

use Appwrite\AppwriteException;
use Override;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Destinations\IPRange;
use Utopia\Client\Destinations\PublicInternet;
use Utopia\Migration\Exception;
use Utopia\Migration\Sources\Appwrite\Client;
use Utopia\Migration\Tests\Network\Server;

final class TargetDestinationsTest extends TestCase
{
    private const string REFUSED = 'not an allowed destination';

    private Server $server;

    #[Override]
    protected function setUp(): void
    {
        $this->server = new Server();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testTargetConnectsAnywhereByDefault(): void
    {
        $response = $this->target()->request('GET', '/echo');

        $this->assertIsArray($response);
        $this->assertSame('/echo', $response['uri']);
    }

    public function testTargetRefusesAnAddressItsDestinationsDoNotAllow(): void
    {
        $target = $this->target()->setDestinations(new PublicInternet());

        try {
            $target->request('GET', '/echo');
            $this->fail('A loopback address must be refused under PublicInternet.');
        } catch (\Exception $error) {
            $this->assertSame(Exception::CODE_INTERNAL, $error->getCode());
            $this->assertStringContainsString(self::REFUSED, $error->getMessage());
        }

        $this->assertSame([], $this->server->getRequests());
    }

    public function testTargetReachesARangeItsDestinationsAdmit(): void
    {
        $target = $this->target()->setDestinations(new PublicInternet(new IPRange('127.0.0.0/8')));

        $response = $target->request('GET', '/echo');

        $this->assertIsArray($response);
        $this->assertSame('/echo', $response['uri']);
    }

    public function testTargetAppliesDestinationsSetAfterARequest(): void
    {
        $target = $this->target();
        $target->request('GET', '/echo');

        $target->setDestinations(new PublicInternet());

        $this->expectExceptionMessage(self::REFUSED);
        $target->request('GET', '/echo');
    }

    public function testSdkClientRefusesAnAddressItsDestinationsDoNotAllow(): void
    {
        $client = $this->client()->setDestinations(new PublicInternet());

        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage(self::REFUSED);
        $client->call(Client::METHOD_GET, '/echo');
    }

    public function testSdkClientReachesARangeItsDestinationsAdmit(): void
    {
        $client = $this->client()->setDestinations(new PublicInternet(new IPRange('127.0.0.0/8')));

        $response = $client->call(Client::METHOD_GET, '/echo');

        $this->assertIsArray($response);
        $this->assertSame('/echo', $response['uri']);
    }

    public function testAppwriteSourcePassesItsDestinationsToTheSdkClient(): void
    {
        $source = new DestinationsSource('project', $this->server->getURL(), 'key', fn () => null);

        $response = $source->sdk()->call(Client::METHOD_GET, '/echo');
        $this->assertIsArray($response);

        $source->setDestinations(new PublicInternet());

        $this->expectExceptionMessage(self::REFUSED);
        $source->sdk()->call(Client::METHOD_GET, '/echo');
    }

    private function target(): RequestTarget
    {
        return new RequestTarget($this->server->getURL());
    }

    private function client(): Client
    {
        $client = new Client();
        $client->setEndpoint($this->server->getURL());

        return $client;
    }
}
