<?php

declare(strict_types=1);

namespace Utopia\Span\Tests\Exporter;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client as HttpClient;
use Utopia\Psr7\Response\Factory as ResponseFactory;
use Utopia\Span\Exporter\Sentry;
use Utopia\Span\Exporter\SentryField;
use Utopia\Span\Level;
use Utopia\Span\Span;

class NamespacedTestException extends \RuntimeException {}

class SentryTest extends TestCase
{
    /**
     * Build the envelope for a span and return the decoded event payload.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Span $span): array
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');

        $method = new \ReflectionMethod(Sentry::class, 'buildEnvelope');
        $envelope = $method->invoke($exporter, $span);

        $this->assertIsString($envelope);

        $lines = explode("\n", $envelope);
        $this->assertCount(3, $lines);

        $payload = json_decode($lines[2], true);
        $this->assertIsArray($payload);

        return $payload;
    }

    public function testConstructorParsesDsn(): void
    {
        $exporter = new Sentry(dsn: 'https://publickey@sentry.io/123456');

        $this->assertInstanceOf(Sentry::class, $exporter);
    }

    public function testConstructorDefaultsToUtopiaClient(): void
    {
        $exporter = new Sentry(dsn: 'https://publickey@sentry.io/123456');
        $property = new \ReflectionProperty(Sentry::class, 'client');

        $this->assertInstanceOf(HttpClient::class, $property->getValue($exporter));
    }

    public function testConstructorThrowsOnInvalidDsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Sentry DSN');

        new Sentry(dsn: 'http:///invalid');
    }

    public function testConstructorThrowsOnEmptyDsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentry DSN is required');

        new Sentry();
    }

    public function testConstructorThrowsOnDsnMissingPublicKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Sentry(dsn: 'https://sentry.io/123');
    }

    public function testConstructorThrowsOnDsnMissingProjectId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Sentry(dsn: 'https://key@sentry.io');
    }

    public function testConstructorHandlesDsnWithPort(): void
    {
        $exporter = new Sentry(dsn: 'https://publickey@sentry.example.com:9000/123');

        $this->assertInstanceOf(Sentry::class, $exporter);
    }

    public function testConstructorHandlesHttpDsn(): void
    {
        $exporter = new Sentry(dsn: 'http://publickey@localhost/123');

        $this->assertInstanceOf(Sentry::class, $exporter);
    }

    public function testExportDoesNotThrowWithValidSpan(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->set('action', 'test');
        $span->finish();

        // Export will fail to connect but should not throw
        // (curl will timeout but we catch that)
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportHandlesSpanWithParentId(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->set('span.parent_id', 'abc123def456');
        $span->finish();

        // Should not throw
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportHandlesSpanWithError(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->setError(new \RuntimeException('Test error'));
        $span->finish();

        // Should not throw
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportUsesInjectedPsr18Client(): void
    {
        $client = new class implements ClientInterface {
            public ?RequestInterface $request = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new ResponseFactory()->createResponse(202);
            }
        };
        $exporter = new Sentry(
            dsn: 'https://publickey@sentry.example.com:9000/123',
            client: $client,
        );
        $span = new Span('test');
        $span->finish(error: new \RuntimeException('Test error'));

        $exporter->export($span);

        $this->assertNotNull($client->request);
        $this->assertSame('POST', $client->request->getMethod());
        $this->assertSame('https://sentry.example.com:9000/api/123/envelope/', (string) $client->request->getUri());
        $this->assertSame('application/x-sentry-envelope', $client->request->getHeaderLine('Content-Type'));
        $this->assertSame(
            'Sentry sentry_version=7, sentry_key=publickey',
            $client->request->getHeaderLine('X-Sentry-Auth'),
        );
        $this->assertStringContainsString('"type":"event"', (string) $client->request->getBody());
        $this->assertStringContainsString('"value":"Test error"', (string) $client->request->getBody());
    }

    public function testExportHandlesSpanWithAllAttributes(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->set('action', 'http.request');
        $span->set('user.id', '123');
        $span->set('request.method', 'POST');
        $span->set('response.status', 200);
        $span->set('span.parent_id', 'parent123');
        $span->finish();

        // Should not throw
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportHandlesHttpConventionAttributes(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span('http.request');
        $span->set('http.url', 'https://api.example.com/users');
        $span->set('http.method', 'POST');
        $span->set('http.query', 'page=1&limit=10');
        $span->set('http.response.status_code', 201);
        $span->setError(new \RuntimeException('Request failed'));
        $span->finish();

        // Should not throw - HTTP attributes are mapped to Sentry request/response
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testSampleSkipsInfoLevelSpans(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->finish();

        $this->assertFalse($exporter->sample($span));
    }

    public function testSampleSendsWarningLevelSpans(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->finish(level: Level::Warn, error: new \RuntimeException('Heads up'));

        $this->assertTrue($exporter->sample($span));
    }

    public function testSampleSendsErrorLevelSpans(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->setError(new \RuntimeException('Boom'));
        $span->finish();

        $this->assertTrue($exporter->sample($span));
    }

    public function testSampleSkipsDowngradedErrorSpans(): void
    {
        $exporter = new Sentry(dsn: 'https://key@sentry.io/123');
        $span = new Span();
        $span->finish(level: Level::Info, error: new \RuntimeException('Handled, not worth reporting'));

        $this->assertFalse($exporter->sample($span));
    }

    public function testSampleComposesCustomSamplerWithLevelFilter(): void
    {
        $exporter = new Sentry(
            sampler: fn(Span $span): bool => $span->getAction() === 'keep',
            dsn: 'https://key@sentry.io/123',
        );

        $kept = new Span('keep');
        $kept->finish(level: Level::Warn, error: new \RuntimeException('Test'));

        $dropped = new Span('drop');
        $dropped->finish(level: Level::Warn, error: new \RuntimeException('Test'));

        $this->assertTrue($exporter->sample($kept));
        $this->assertFalse($exporter->sample($dropped));
    }

    public function testExportWithClassifier(): void
    {
        $exporter = new Sentry(
            dsn: 'https://key@sentry.io/123',
            classifier: fn(string $key): SentryField => match (true) {
                str_starts_with($key, 'tenant.') => SentryField::Tag,
                str_starts_with($key, 'user.') => SentryField::Context,
                default => SentryField::Extra,
            },
        );

        $span = new Span('api.request');
        $span->set('tenant.id', 'acme-corp');
        $span->set('user.id', '12345');
        $span->set('user.email', 'test@example.com');
        $span->set('debug.info', 'some debug data');
        $span->setError(new \RuntimeException('Test error'));
        $span->finish();

        // Should not throw - classifier distributes attributes correctly
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testEnvelopeContainsChainedExceptions(): void
    {
        $root = new \LogicException('root cause');
        $middle = new \InvalidArgumentException('middle', 0, $root);
        $outer = new \RuntimeException('outer', 0, $middle);

        $span = new Span('test');
        $span->finish(error: $outer);

        $values = $this->buildPayload($span)['exception']['values'];

        $this->assertCount(3, $values);

        // Oldest cause first, reported exception last
        $this->assertSame(\LogicException::class, $values[0]['type']);
        $this->assertSame('root cause', $values[0]['value']);
        $this->assertSame(\InvalidArgumentException::class, $values[1]['type']);
        $this->assertSame(\RuntimeException::class, $values[2]['type']);
        $this->assertSame('outer', $values[2]['value']);

        // The reported exception is the root of the mechanism tree (id 0)
        $this->assertSame(0, $values[2]['mechanism']['exception_id']);
        $this->assertArrayNotHasKey('parent_id', $values[2]['mechanism']);
        $this->assertSame(1, $values[1]['mechanism']['exception_id']);
        $this->assertSame(0, $values[1]['mechanism']['parent_id']);
        $this->assertSame('__previous__', $values[1]['mechanism']['source']);
        $this->assertSame(2, $values[0]['mechanism']['exception_id']);
        $this->assertSame(1, $values[0]['mechanism']['parent_id']);

        foreach ($values as $value) {
            $this->assertNotEmpty($value['stacktrace']['frames']);
        }
    }

    public function testEnvelopeSingleExceptionHasNoChainIds(): void
    {
        $span = new Span('test');
        $span->finish(error: new \RuntimeException('alone'));

        $values = $this->buildPayload($span)['exception']['values'];

        $this->assertCount(1, $values);
        $this->assertSame('generic', $values[0]['mechanism']['type']);
        $this->assertArrayNotHasKey('exception_id', $values[0]['mechanism']);
        $this->assertArrayNotHasKey('parent_id', $values[0]['mechanism']);
    }

    public function testEnvelopeMechanismHandledReflectsLevel(): void
    {
        $handledSpan = new Span('test');
        $handledSpan->finish(level: Level::Error, error: new \RuntimeException('caught'));

        $values = $this->buildPayload($handledSpan)['exception']['values'];
        $this->assertTrue($values[0]['mechanism']['handled']);

        $fatalSpan = new Span('test');
        $fatalSpan->finish(level: Level::Fatal, error: new \RuntimeException('crashed'));

        $values = $this->buildPayload($fatalSpan)['exception']['values'];
        $this->assertFalse($values[0]['mechanism']['handled']);
    }

    public function testEnvelopeMechanismHandledAttributeOverridesLevel(): void
    {
        $unhandledError = new Span('test');
        $unhandledError->set('span.handled', false);
        $unhandledError->finish(level: Level::Error, error: new \RuntimeException('escaped'));

        $values = $this->buildPayload($unhandledError)['exception']['values'];
        $this->assertFalse($values[0]['mechanism']['handled']);

        $handledFatal = new Span('test');
        $handledFatal->set('span.handled', true);
        $handledFatal->finish(level: Level::Fatal, error: new \RuntimeException('caught but fatal'));

        $values = $this->buildPayload($handledFatal)['exception']['values'];
        $this->assertTrue($values[0]['mechanism']['handled']);
    }

    public function testEnvelopeIncludesExceptionModule(): void
    {
        $span = new Span('test');
        $span->finish(error: new NamespacedTestException('namespaced'));

        $values = $this->buildPayload($span)['exception']['values'];

        $this->assertSame(NamespacedTestException::class, $values[0]['type']);
        $this->assertSame(__NAMESPACE__, $values[0]['module']);

        $globalSpan = new Span('test');
        $globalSpan->finish(error: new \RuntimeException('global'));

        $values = $this->buildPayload($globalSpan)['exception']['values'];
        $this->assertArrayNotHasKey('module', $values[0]);
    }

    public function testEnvelopeFramesCarryFunctionNames(): void
    {
        $error = $this->throwAndCatch();

        $span = new Span('test');
        $span->finish(error: $error);

        $frames = $this->buildPayload($span)['exception']['values'][0]['stacktrace']['frames'];

        $this->assertNotEmpty($frames);

        foreach ($frames as $frame) {
            if (\array_key_exists('function', $frame)) {
                $this->assertNotSame('', $frame['function']);
            }
        }

        // The throw-site frame is last and names its enclosing function
        $throwSite = end($frames);
        $this->assertSame(self::class . '->throwAndCatch', $throwSite['function']);
        $this->assertSame(__FILE__, $throwSite['filename']);
    }

    public function testEnvelopeCapsExceptionChainDepth(): void
    {
        $error = new \RuntimeException('level 0');
        for ($i = 1; $i < 15; $i++) {
            $error = new \RuntimeException("level {$i}", 0, $error);
        }

        $span = new Span('test');
        $span->finish(error: $error);

        $values = $this->buildPayload($span)['exception']['values'];

        $this->assertCount(10, $values);
        // Reported exception is always kept (last); the deepest causes are dropped
        $this->assertSame('level 14', $values[9]['value']);
        $this->assertSame('level 5', $values[0]['value']);
    }

    private function throwAndCatch(): \Throwable
    {
        try {
            throw new \RuntimeException('thrown here');
        } catch (\RuntimeException $error) {
            return $error;
        }
    }
}
