<?php

declare(strict_types=1);

namespace Tests\Compat\Messaging;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Compat\Fault;
use Utopia\Client\Adapter\Curl\Client as Curl;
use Utopia\Client\Client;
use Utopia\Client\Destinations;
use Utopia\Client\Destinations\Anywhere;
use Utopia\Messaging\Adapter\Chat\Discord;
use Utopia\Messaging\Adapter\Email\Mailgun;
use Utopia\Messaging\Adapter\Email\Resend;
use Utopia\Messaging\Adapter\Email\Sendgrid;
use Utopia\Messaging\Adapter\Email\SES;
use Utopia\Messaging\Adapter\Push\APNS;
use Utopia\Messaging\Adapter\Push\FCM;
use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\Bird;
use Utopia\Messaging\Adapter\SMS\Clickatell;
use Utopia\Messaging\Adapter\SMS\Fast2SMS;
use Utopia\Messaging\Adapter\SMS\Infobip;
use Utopia\Messaging\Adapter\SMS\Inforu;
use Utopia\Messaging\Adapter\SMS\Mock as MockSMS;
use Utopia\Messaging\Adapter\SMS\Msg91;
use Utopia\Messaging\Adapter\SMS\Plivo;
use Utopia\Messaging\Adapter\SMS\Seven;
use Utopia\Messaging\Adapter\SMS\Sinch;
use Utopia\Messaging\Adapter\SMS\Telesign;
use Utopia\Messaging\Adapter\SMS\Telnyx;
use Utopia\Messaging\Adapter\SMS\TextMagic;
use Utopia\Messaging\Adapter\SMS\Twilio;
use Utopia\Messaging\Adapter\SMS\Vonage;
use Utopia\Messaging\Adapter\SMS\WhatsApp;
use Utopia\Messaging\Messages\SMS;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;
use Utopia\Telemetry\UpDownCounter;

/**
 * How a routed adapter's requests are answered, the twin of `Router` in
 * crates/tools/compat/src/libs/messaging.rs.
 *
 * `stub` replaces `request()` and `requestMulti()` as the library's own
 * tests do (BirdStub, SESStub, ...): each call is captured with its
 * arguments and answered with the next queued reply. `fixture` keeps the
 * library's requests and sends them to the `messaging-http` fixture server,
 * the provider URL rewritten to /<ns>/<scheme>/<host>/<path>; the replies the
 * adapter sees carry the provider URL again.
 */
final class Router
{
    /** @var list<array<string, mixed>> */
    public array $captured = [];

    /** @var list<array<string, mixed>> */
    private array $replies = [];

    public function __construct(
        public readonly string $mode,
        private readonly string $fixture = '',
        private readonly string $ns = '',
    ) {
    }

    /**
     * @param list<mixed> $replies
     */
    public function queue(array $replies): void
    {
        if ($this->mode !== 'fixture') {
            foreach ($replies as $reply) {
                $this->replies[] = (array) $reply;
            }

            return;
        }
        if ($replies !== []) {
            $this->call('/_queue/' . $this->ns, json_encode($replies, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * The requests made since the last take: captured calls, or what the
     * fixture server received.
     *
     * @return list<mixed>
     */
    public function take(bool $sort): array
    {
        if ($this->mode !== 'fixture') {
            $captured = $this->captured;
            $this->captured = [];
            $this->replies = [];

            return $captured;
        }

        return json_decode($this->call('/_take/' . $this->ns . ($sort ? '?sort=1' : ''), null), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string> $headers
     * @param array<string, mixed>|null $body
     * @param Closure(string, string, array<string>, array<string, mixed>|null, int, int): array<string, mixed> $parent
     * @return array<string, mixed>
     */
    public function request(string $method, string $url, array $headers, ?array $body, int $timeout, int $connectTimeout, Closure $parent): array
    {
        if ($this->mode !== 'fixture') {
            if ($this->mode === 'stub') {
                $this->captured[] = Normal::call(['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body]);
            }

            return ['url' => $url] + $this->reply();
        }

        $result = $parent($method, $this->rewrite($url), $headers, $body, $timeout, $connectTimeout);
        $result['url'] = $url;

        return $result;
    }

    /**
     * @param array<string> $urls
     * @param array<string> $headers
     * @param array<array<string, mixed>> $bodies
     * @param Closure(string, array<string>, array<string>, array<array<string, mixed>>, int, int): array<array<string, mixed>> $parent
     * @return array<array<string, mixed>>
     */
    public function requestMulti(string $method, array $urls, array $headers, array $bodies, int $timeout, int $connectTimeout, Closure $parent): array
    {
        if ($this->mode !== 'fixture') {
            if ($this->mode === 'stub') {
                $this->captured[] = Normal::call(['method' => $method, 'urls' => $urls, 'headers' => $headers, 'bodies' => $bodies]);
            }
            $results = [];
            for ($i = 0; $i < max(\count($urls), \count($bodies)); $i++) {
                $results[] = ['index' => $i, 'url' => $urls[$i] ?? $urls[0] ?? ''] + $this->reply();
            }

            return $results;
        }

        $results = $parent($method, array_map($this->rewrite(...), $urls), $headers, $bodies, $timeout, $connectTimeout);
        foreach ($results as &$result) {
            $result['url'] = $this->restore((string) $result['url']);
        }

        return $results;
    }

    /**
     * @return array{statusCode: int, response: mixed, headers: array<string, string>, error: string, errorCode: int}
     */
    private function reply(): array
    {
        $reply = array_shift($this->replies) ?? [];

        return [
            'statusCode' => (int) ($reply['statusCode'] ?? 200),
            // As json_decode($body, true) reads a body: objects are arrays.
            'response' => \array_key_exists('response', $reply) ? json_decode((string) json_encode($reply['response']), true) : [],
            'headers' => (array) ($reply['headers'] ?? []),
            'error' => (string) ($reply['error'] ?? ''),
            'errorCode' => (int) ($reply['errorCode'] ?? 0),
        ];
    }

    /** https://host/path?query as <fixture>/<ns>/https/host/path?query. */
    public function rewrite(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }
        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $this->fixture . '/' . $this->ns . '/' . $parts['scheme'] . '/' . $authority
            . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function restore(string $url): string
    {
        $prefix = $this->fixture . '/' . $this->ns . '/';
        if (!str_starts_with($url, $prefix)) {
            return $url;
        }
        [$scheme, $rest] = explode('/', substr($url, \strlen($prefix)), 2) + [1 => ''];

        return $scheme . '://' . $rest;
    }

    private function call(string $path, ?string $body): string
    {
        $context = stream_context_create(['http' => [
            'method' => $body === null ? 'GET' : 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $body ?? '',
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($this->fixture . $path, false, $context);
        if ($result === false) {
            throw new Fault('the messaging-http fixture server is not reachable (tests/compat/messaging/services.sh up)');
        }

        return $result;
    }
}

/**
 * Routes an adapter's requests through a Router once one is set.
 */
trait Routed
{
    private ?Router $router = null;

    public function route(Router $router): static
    {
        $this->router = $router;

        return $this;
    }

    protected function request(string $method, string $url, array $headers = [], ?array $body = null, int $timeout = 30, int $connectTimeout = 10): array
    {
        if (!$this->router instanceof Router) {
            return parent::request($method, $url, $headers, $body, $timeout, $connectTimeout);
        }

        return $this->router->request($method, $url, $headers, $body, $timeout, $connectTimeout, parent::request(...));
    }

    protected function requestMulti(string $method, array $urls, array $headers = [], array $bodies = [], int $timeout = 30, int $connectTimeout = 10): array
    {
        if (!$this->router instanceof Router) {
            return parent::requestMulti($method, $urls, $headers, $bodies, $timeout, $connectTimeout);
        }

        return $this->router->requestMulti($method, $urls, $headers, $bodies, $timeout, $connectTimeout, parent::requestMulti(...));
    }

    protected function destinations(): Destinations
    {
        return $this->router instanceof Router ? new Anywhere() : parent::destinations();
    }
}

final class RoutedMailgun extends Mailgun
{
    use Routed;
}

final class RoutedSendgrid extends Sendgrid
{
    use Routed;
}

final class RoutedResend extends Resend
{
    use Routed;
}

final class RoutedSES extends SES
{
    use Routed;

    public function withService(string $service): self
    {
        $this->service = $service;

        return $this;
    }

    /**
     * @param array<string, string> $signedHeaders
     */
    public function callSign(string $method, string $path, string $payload, array $signedHeaders, string $amzDate): string
    {
        return $this->sign($method, $path, $payload, $signedHeaders, $amzDate);
    }
}

final class RoutedBird extends Bird
{
    use Routed;
}

final class RoutedClickatell extends Clickatell
{
    use Routed;
}

final class RoutedFast2SMS extends Fast2SMS
{
    use Routed;
}

final class RoutedInfobip extends Infobip
{
    use Routed;
}

final class RoutedInforu extends Inforu
{
    use Routed;
}

final class RoutedMockSMS extends MockSMS
{
    use Routed;
}

final class RoutedMsg91 extends Msg91
{
    use Routed;
}

final class RoutedPlivo extends Plivo
{
    use Routed;
}

final class RoutedSeven extends Seven
{
    use Routed;
}

final class RoutedSinch extends Sinch
{
    use Routed;
}

final class RoutedTelesign extends Telesign
{
    use Routed;
}

final class RoutedTelnyx extends Telnyx
{
    use Routed;
}

final class RoutedTextMagic extends TextMagic
{
    use Routed;
}

final class RoutedTwilio extends Twilio
{
    use Routed;
}

final class RoutedVonage extends Vonage
{
    use Routed;
}

final class RoutedWhatsApp extends WhatsApp
{
    use Routed;
}

final class RoutedAPNS extends APNS
{
    use Routed;
}

final class RoutedFCM extends FCM
{
    use Routed;
}

final class RoutedDiscord extends Discord
{
    use Routed;
}

/**
 * A PSR-18 client sending to the fixture server: the client a custom
 * `$clientFactory` returns in the `custom` transport.
 */
final class FixtureClient implements ClientInterface
{
    private Client $client;

    public function __construct(private readonly Router $router)
    {
        $this->client = new Client(new Curl(), new Anywhere());
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $target = (new \Utopia\Psr7\Uri\Factory())->createUri($this->router->rewrite((string) $uri));

        return $this->client->sendRequest($request->withUri($target));
    }
}

/**
 * Records every measurement of the `messaging.send` counter, as
 * TelemetryTest's RecordingTelemetry does.
 */
final class RecordingTelemetry implements Telemetry
{
    /** @var list<array{amount: int|float, attributes: array<string, mixed>}> */
    public array $records = [];

    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Counter
    {
        return new class ($this) extends Counter {
            public function __construct(private readonly RecordingTelemetry $telemetry)
            {
            }

            public function add(float|int $amount, iterable $attributes = []): void
            {
                $copy = [];
                foreach ($attributes as $key => $value) {
                    $copy[$key] = $value;
                }
                $this->telemetry->records[] = ['amount' => $amount, 'attributes' => $copy];
            }
        };
    }

    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Histogram
    {
        throw new \BadMethodCallException();
    }

    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Gauge
    {
        throw new \BadMethodCallException();
    }

    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounter
    {
        throw new \BadMethodCallException();
    }

    public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): ObservableGauge
    {
        throw new \BadMethodCallException();
    }

    public function collect(): bool
    {
        return true;
    }
}

/**
 * An SMS adapter answering from a script, as TelemetryTest's
 * TelemetryAdapter and GEOSMSTest's stubs do: a canned response, or an
 * exception, and every message it was given.
 */
final class ScriptedSMS extends SMSAdapter
{
    /** @var list<SMS> */
    public array $messages = [];

    /**
     * @param array<string, mixed>|null $response
     */
    public function __construct(
        private readonly string $name,
        private readonly int $max,
        private readonly ?array $response,
        private readonly ?string $error,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return $this->max;
    }

    /**
     * @return array<string, mixed>
     */
    protected function process(SMS $message): array
    {
        $this->messages[] = $message;
        if ($this->error !== null) {
            throw new \Exception($this->error);
        }

        return $this->response ?? ['deliveredTo' => 0, 'type' => 'sms', 'results' => []];
    }
}

/**
 * What differs between runs, normalised the same way on both sides: JWTs
 * (decoded, iat and exp made relative, signatures verified with the fixture
 * keys), SigV4 dates and signatures, raw MIME messages.
 */
final class Normal
{
    public const string KEYS = 'tests/compat/messaging/fixtures/keys';

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function call(array $call): array
    {
        $tokens = [];
        // request() reads the values of $headers only (Vonage passes one keyed by name).
        $call['headers'] = array_map(static function (mixed $header) use (&$tokens): mixed {
            if (!\is_string($header)) {
                return $header;
            }
            if (preg_match('/^Authorization: Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*)$/', $header, $m) === 1) {
                $tokens[] = self::jwt($m[1]);

                return 'Authorization: Bearer <jwt>';
            }
            if (str_starts_with($header, 'X-Amz-Date: ')) {
                return 'X-Amz-Date: <amz-date>';
            }
            if (str_starts_with($header, 'Authorization: AWS4-HMAC-SHA256 ')) {
                return (string) preg_replace(['#/\d{8}/#', '/Signature=[0-9a-f]{64}$/'], ['/<date>/', 'Signature=<signature>'], $header);
            }

            return $header;
        }, array_values($call['headers']));
        foreach (['body', 'bodies'] as $key) {
            if (isset($call[$key]) && \is_array($call[$key])) {
                $call[$key] = self::body($call[$key], $tokens, $key === 'bodies');
            }
        }
        if ($tokens !== []) {
            $call['jwt'] = $tokens;
        }

        return $call;
    }

    /**
     * @param array<mixed> $body
     * @param list<mixed> $tokens
     * @return array<mixed>
     */
    private static function body(array $body, array &$tokens, bool $many): array
    {
        if ($many) {
            foreach ($body as $i => $one) {
                $body[$i] = \is_array($one) ? self::body($one, $tokens, false) : $one;
            }

            return $body;
        }
        if (isset($body['assertion']) && \is_string($body['assertion'])) {
            $tokens[] = self::jwt($body['assertion']);
            $body['assertion'] = '<jwt>';
        }
        if (isset($body['Content']['Raw']['Data']) && \is_string($body['Content']['Raw']['Data'])) {
            $body['Content']['Raw']['Data'] = self::mime((string) base64_decode($body['Content']['Raw']['Data']));
        }
        foreach ($body as $key => $value) {
            if ($value instanceof \Utopia\Psr7\Request\Multipart\Part) {
                $body[$key] = ['$part' => [
                    'name' => $value->name(),
                    'filename' => $value->filename(),
                    'type' => $value->contentType(),
                    'path' => $value->path(),
                ]];
            }
        }

        return $body;
    }

    /**
     * A JWT decoded: header, payload with iat as `<now>` and exp as
     * `<iat+N>` (when iat is within ten minutes of now), and whether the
     * fixture key of its algorithm verifies it.
     *
     * @return array<string, mixed>
     */
    public static function jwt(string $token): array
    {
        $parts = explode('.', $token);
        if (\count($parts) !== 3) {
            return ['malformed' => $token];
        }
        $decode = static fn (string $v): string|false => base64_decode(strtr($v, '-_', '+/'), true);
        $header = json_decode((string) $decode($parts[0]), true);
        $payload = json_decode((string) $decode($parts[1]), true);
        $signature = (string) $decode($parts[2]);
        $alg = \is_array($header) ? (string) ($header['alg'] ?? '') : '';

        return [
            'header' => $header,
            'payload' => self::relative($payload),
            'verified' => self::verify($parts[0] . '.' . $parts[1], $signature, $alg),
        ];
    }

    private static function relative(mixed $payload): mixed
    {
        if (\is_array($payload) && \is_int($payload['iat'] ?? null) && abs($payload['iat'] - time()) <= 600) {
            if (\is_int($payload['exp'] ?? null)) {
                $payload['exp'] = '<iat+' . ($payload['exp'] - $payload['iat']) . '>';
            }
            $payload['iat'] = '<now>';
        }

        return $payload;
    }

    /** Whether the fixture key of `$alg` verifies the signature. */
    public static function verify(string $signed, string $signature, string $alg): bool
    {
        $file = self::KEYS . '/' . strtolower($alg) . '.pub.pem';
        if (!is_file($file)) {
            return false;
        }
        $digest = str_ends_with($alg, '384') ? OPENSSL_ALGO_SHA384 : (str_ends_with($alg, '512') ? OPENSSL_ALGO_SHA512 : OPENSSL_ALGO_SHA256);
        if (str_starts_with($alg, 'ES')) {
            $signature = self::der($signature);
        }

        return openssl_verify($signed, $signature, (string) file_get_contents($file), $digest) === 1;
    }

    /** ECDSA r||s as DER. */
    private static function der(string $rs): string
    {
        $half = intdiv(\strlen($rs), 2);
        $int = static function (string $v): string {
            $v = ltrim($v, "\0");
            if ($v === '' || \ord($v[0]) > 0x7f) {
                $v = "\0" . $v;
            }

            return "\x02" . \chr(\strlen($v)) . $v;
        };
        $seq = $int(substr($rs, 0, $half)) . $int(substr($rs, $half));

        return "\x30" . \chr(\strlen($seq)) . $seq;
    }

    /** MIME text with random boundaries renamed in order, Date and Message-ID masked. */
    public static function mime(string $mime): string
    {
        $names = [];
        $offset = 0;
        while (($at = strpos($mime, '=_', $offset)) !== false) {
            $candidate = substr($mime, $at, 34);
            if (\strlen($candidate) === 34 && strspn($candidate, '0123456789abcdef', 2) === 32) {
                $names[$candidate] ??= '=_BOUNDARY' . (\count($names) + 1);
                $offset = $at + 34;
            } else {
                $offset = $at + 1;
            }
        }
        $mime = strtr($mime, $names);
        $mime = (string) preg_replace('/^Date: [^\r\n]*/m', 'Date: <date>', $mime);

        return (string) preg_replace('/^Message-ID: <[0-9a-f]{32}@/m', 'Message-ID: <<id>@', $mime);
    }
}
