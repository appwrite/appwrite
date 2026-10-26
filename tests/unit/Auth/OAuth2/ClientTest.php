<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Client\Client;
use Utopia\Client\Destinations\IPRange;
use Utopia\Client\Destinations\PublicInternet;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

final class ClientTest extends TestCase
{
    public static function clients(): \Iterator
    {
        yield 'oauth2' => ['clientForOAuth2'];
        yield 'webhooks' => ['clientForWebhooks'];
    }

    #[DataProvider('clients')]
    public function testCompressedResponsePastTheCapIsAbandoned(string $name): void
    {
        global $container;

        $router = \sys_get_temp_dir() . '/compressed-' . \bin2hex(\random_bytes(6)) . '.php';
        \file_put_contents($router, '<?php header("Content-Encoding: gzip"); echo gzencode(str_repeat(" ", ' . (APP_STORAGE_READ_BUFFER + 1) . '), 9);');
        $socket = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);
        $server = \proc_open([PHP_BINARY, '-S', $address, $router], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($server);

        try {
            for ($attempt = 0; $attempt < 100 && @\stream_socket_client('tcp://' . $address) === false; $attempt++) {
                \usleep(50_000);
            }

            $client = $container->get($name);
            $this->assertInstanceOf(Client::class, $client);

            $error = null;
            try {
                $client
                    ->withDestinations(new PublicInternet(new IPRange('127.0.0.1')))
                    ->sendRequest((new RequestFactory())->createRequest(Method::GET, 'http://' . $address . '/'));
            } catch (ClientExceptionInterface $caught) {
                $error = $caught;
            }

            $this->assertInstanceOf(ClientExceptionInterface::class, $error, 'A body decoding past the cap must not be returned');
            $this->assertSame(\CURLE_FILESIZE_EXCEEDED, $error->getCode(), $error->getMessage());
        } finally {
            \proc_terminate($server);
            \proc_close($server);
            \unlink($router);
        }
    }
}
