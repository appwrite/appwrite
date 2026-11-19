<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Tests\Support\ScriptedSmtpServer;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

/**
 * Microsoft Entra ID (Azure AD) OAuth 2.0 authentication over XOAUTH2.
 */
final class SMTPOAuthTest extends TestCase
{
    private const array SESSION = [
        '220 smtp.office365.com ESMTP',
        "250-smtp.office365.com\r\n250-AUTH XOAUTH2\r\n250 8BITMIME",
        '235 2.7.0 Authentication successful',
        '250 2.1.0 Ok',
        '250 2.1.5 Ok',
        '354 End data with <CR><LF>.<CR><LF>',
        '250 2.0.0 Ok: queued',
        '221 2.0.0 Bye',
    ];

    public function testFetchesEntraTokenAndAuthenticatesWithXOAuth2(): void
    {
        $server = new ScriptedSmtpServer(self::SESSION);
        $client = new TokenMockClient([
            new Response(200, body: new Stream((string) json_encode([
                'access_token' => 'entra-access-token',
                'expires_in' => 3600,
            ]))),
        ]);

        $adapter = new SMTP(
            host: "127.0.0.1:{$server->port}",
            username: 'sender@contoso.com',
            timeout: 2,
            timelimit: 2,
            clientId: 'my-client-id',
            clientSecret: 'my-client-secret',
            tenantId: 'my-tenant-id',
            clientFactory: static fn (): ClientInterface => $client,
        );

        $result = $adapter->send($this->email());

        $this->assertSame(1, $result['deliveredTo']);
        $this->assertCount(1, $client->requests);
        $request = $client->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://login.microsoftonline.com/my-tenant-id/oauth2/v2.0/token', $request['url']);
        $this->assertStringContainsString('application/x-www-form-urlencoded', $request['contentType']);
        $this->assertSame([
            'client_id' => 'my-client-id',
            'client_secret' => 'my-client-secret',
            'scope' => 'https://outlook.office365.com/.default',
            'grant_type' => 'client_credentials',
        ], $request['form']);

        $expectedAuth = 'AUTH XOAUTH2 ' . base64_encode("user=sender@contoso.com\1auth=Bearer entra-access-token\1\1");
        $commands = $server->commands();
        $this->assertContains($expectedAuth, $commands);
    }

    public function testReusesCachedTokenBeforeRefreshWindow(): void
    {
        $server = new ScriptedSmtpServer(self::SESSION, self::SESSION);
        $client = new TokenMockClient([
            new Response(200, body: new Stream((string) json_encode([
                'access_token' => 'cached-token',
                'expires_in' => 3600,
            ]))),
        ]);

        $adapter = new SMTP(
            host: "127.0.0.1:{$server->port}",
            username: 'sender@contoso.com',
            timeout: 2,
            timelimit: 2,
            clientId: 'cid',
            clientSecret: 'csec',
            tenantId: 'tid',
            clientFactory: static fn (): ClientInterface => $client,
        );

        $this->assertSame(1, $adapter->send($this->email())['deliveredTo']);
        $this->assertSame(1, $adapter->send($this->email())['deliveredTo']);
        $this->assertCount(1, $client->requests);
    }

    public function testRefreshesTokenInsideRefreshBufferWindow(): void
    {
        $server = new ScriptedSmtpServer(self::SESSION, self::SESSION);
        $client = new TokenMockClient([
            new Response(200, body: new Stream((string) json_encode([
                'access_token' => 'token-1',
                'expires_in' => 30,
            ]))),
            new Response(200, body: new Stream((string) json_encode([
                'access_token' => 'token-2',
                'expires_in' => 3600,
            ]))),
        ]);

        $adapter = new SMTP(
            host: "127.0.0.1:{$server->port}",
            username: 'sender@contoso.com',
            timeout: 2,
            timelimit: 2,
            clientId: 'cid',
            clientSecret: 'csec',
            tenantId: 'tid',
            clientFactory: static fn (): ClientInterface => $client,
        );

        $this->assertSame(1, $adapter->send($this->email())['deliveredTo']);
        $this->assertSame(1, $adapter->send($this->email())['deliveredTo']);
        $this->assertCount(2, $client->requests);

        $commands = $server->commands();
        $this->assertContains('AUTH XOAUTH2 ' . base64_encode("user=sender@contoso.com\1auth=Bearer token-1\1\1"), $commands);
        $this->assertContains('AUTH XOAUTH2 ' . base64_encode("user=sender@contoso.com\1auth=Bearer token-2\1\1"), $commands);
    }

    public function testThrowsWhenTokenEndpointReturnsHttpError(): void
    {
        $port = ScriptedSmtpServer::closedPort();
        $client = new TokenMockClient([
            new Response(400, body: new Stream((string) json_encode([
                'error' => 'invalid_client',
                'error_description' => 'AADSTS7000215: Invalid client secret provided.',
            ]))),
        ]);

        $adapter = new SMTP(
            host: "127.0.0.1:{$port}",
            username: 'sender@contoso.com',
            timeout: 2,
            timelimit: 2,
            clientId: 'cid',
            clientSecret: 'bad-secret',
            tenantId: 'tid',
            clientFactory: static fn (): ClientInterface => $client,
        );

        try {
            $adapter->send($this->email());
            $this->fail('Expected exception was not thrown.');
        } catch (\Exception $exception) {
            $this->assertSame(400, $exception->getCode());
            $this->assertStringContainsString('HTTP 400', $exception->getMessage());
            $this->assertStringContainsString('invalid_client', $exception->getMessage());
            $this->assertStringContainsString('AADSTS7000215: Invalid client secret provided.', $exception->getMessage());
        }
    }

    public function testThrows503RetainingStatusAndOAuthErrorCodeWithDescription(): void
    {
        $port = ScriptedSmtpServer::closedPort();
        $client = new TokenMockClient([
            new Response(503, body: new Stream((string) json_encode([
                'error' => 'temporarily_unavailable',
                'error_description' => 'A transient error has occurred. Please try again.',
            ]))),
        ]);

        $adapter = new SMTP(
            host: "127.0.0.1:{$port}",
            username: 'sender@contoso.com',
            timeout: 2,
            timelimit: 2,
            clientId: 'cid',
            clientSecret: 'csec',
            tenantId: 'tid',
            clientFactory: static fn (): ClientInterface => $client,
        );

        try {
            $adapter->send($this->email());
            $this->fail('Expected exception was not thrown.');
        } catch (\Exception $exception) {
            $this->assertSame(503, $exception->getCode());
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
            $this->assertStringContainsString('temporarily_unavailable', $exception->getMessage());
            $this->assertStringContainsString('A transient error has occurred. Please try again.', $exception->getMessage());
        }
    }

    public function testThrows503RetainingStatusAndServerErrorCode(): void
    {
        $port = ScriptedSmtpServer::closedPort();
        $client = new TokenMockClient([
            new Response(503, body: new Stream((string) json_encode([
                'error' => 'server_error',
            ]))),
        ]);

        $adapter = new SMTP(
            host: "127.0.0.1:{$port}",
            username: 'sender@contoso.com',
            timeout: 2,
            timelimit: 2,
            clientId: 'cid',
            clientSecret: 'csec',
            tenantId: 'tid',
            clientFactory: static fn (): ClientInterface => $client,
        );

        try {
            $adapter->send($this->email());
            $this->fail('Expected exception was not thrown.');
        } catch (\Exception $exception) {
            $this->assertSame(503, $exception->getCode());
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
            $this->assertStringContainsString('server_error', $exception->getMessage());
        }
    }

    public function testThrowsWhenTokenResponseOmitsAccessToken(): void
    {
        $port = ScriptedSmtpServer::closedPort();
        $client = new TokenMockClient([
            new Response(200, body: new Stream((string) json_encode([
                'token_type' => 'Bearer',
            ]))),
        ]);

        $adapter = new SMTP(
            host: "127.0.0.1:{$port}",
            username: 'sender@contoso.com',
            timeout: 2,
            timelimit: 2,
            clientId: 'cid',
            clientSecret: 'csec',
            tenantId: 'tid',
            clientFactory: static fn (): ClientInterface => $client,
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to obtain OAuth2 access token: HTTP 200');
        $adapter->send($this->email());
    }

    public function testThrowsWhenHttpClientThrows(): void
    {
        $port = ScriptedSmtpServer::closedPort();
        $client = new TokenMockClient([
            new class ('Network unreachable') extends \RuntimeException implements ClientExceptionInterface {
            },
        ]);

        $adapter = new SMTP(
            host: "127.0.0.1:{$port}",
            username: 'sender@contoso.com',
            timeout: 2,
            timelimit: 2,
            clientId: 'cid',
            clientSecret: 'csec',
            tenantId: 'tid',
            clientFactory: static fn (): ClientInterface => $client,
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Network unreachable');
        $adapter->send($this->email());
    }

    public function testFallsBackToBasicAuthWhenOAuthIsNotConfigured(): void
    {
        $server = new ScriptedSmtpServer([
            '220 smtp.example.test ESMTP',
            "250-smtp.example.test\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME",
            '235 2.7.0 Authentication successful',
            '250 2.1.0 Ok',
            '250 2.1.5 Ok',
            '354 End data with <CR><LF>.<CR><LF>',
            '250 2.0.0 Ok: queued',
            '221 2.0.0 Bye',
        ]);
        $client = new TokenMockClient([]);

        $adapter = new SMTP(
            host: "127.0.0.1:{$server->port}",
            username: 'jane',
            password: 'secret',
            timeout: 2,
            timelimit: 2,
            clientFactory: static fn (): ClientInterface => $client,
        );

        $result = $adapter->send($this->email());

        $this->assertSame(1, $result['deliveredTo']);
        $this->assertCount(0, $client->requests);
        $this->assertContains('AUTH PLAIN ' . base64_encode("\0jane\0secret"), $server->commands());
    }

    private function email(): Email
    {
        return new Email(
            to: [['email' => 'to@example.test', 'name' => 'To']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'sender@contoso.com',
        );
    }
}

final class TokenMockClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, contentType: string, form: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $outcomes
     */
    public function __construct(private array $outcomes)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        $form = [];
        parse_str($body, $form);

        $this->requests[] = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'contentType' => $request->getHeaderLine('Content-Type'),
            'form' => $form,
        ];

        $outcome = array_shift($this->outcomes);
        if ($outcome instanceof ClientExceptionInterface) {
            throw $outcome;
        }

        return $outcome ?? new Response(500);
    }
}
