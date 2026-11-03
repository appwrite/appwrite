<?php

namespace Utopia\Agents;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Swoole\Coroutine;
use Utopia\Agents\Exception\Api;
use Utopia\Agents\Exception\Authentication;
use Utopia\Agents\Exception\RateLimit;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Adapter\SwooleCoroutine\Client as SwooleAdapter;
use Utopia\Client\Client as HttpClient;
use Utopia\Client\Pool as HttpClientPool;
use Utopia\Client\Psr18\StreamingClientInterface;
use Utopia\Pools\Adapter\Swoole as SwoolePoolAdapter;
use Utopia\Pools\Pool as Connections;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;

/**
 * HTTP transport shared by every provider adapter: a pooled keep-alive
 * client, JSON and server-sent-event requests, error mapping and retries
 * with exponential backoff.
 *
 * A request is retried on network errors and on 408, 409, 429 and 5xx
 * responses, honoring Retry-After. A stream is only retried before its first
 * event, so listeners never see a delta twice.
 */
abstract class Adapter
{
    /**
     * Connections the default pooled client keeps per adapter.
     */
    protected const int POOL_SIZE = 8;

    /**
     * Bytes of a response body kept to describe an error.
     */
    protected const int ERROR_BYTES = 65536;

    /**
     * Upper bound for one buffered server-sent event.
     */
    protected const int EVENT_BYTES = 8388608;

    /**
     * Longest wait between retries, in milliseconds.
     */
    protected const int MAX_DELAY = 60000;

    /**
     * Request timeout in milliseconds.
     */
    protected int $timeout = 90000;

    /**
     * Retries after the first attempt.
     */
    protected int $retries = 2;

    /**
     * First backoff delay in milliseconds; doubles on every retry.
     */
    protected int $delay = 500;

    /**
     * HTTP client every request goes through: injected by the constructor or
     * built once here and reused, so connections stay alive across requests.
     */
    protected (ClientInterface&StreamingClientInterface)|null $client = null;

    /**
     * Whether $client was built here (and follows the timeout) or injected.
     */
    protected bool $ownsClient = false;

    protected ?Factory $requests = null;

    /**
     * Provider name.
     */
    abstract public function getName(): string;

    /**
     * Set the request timeout in milliseconds.
     *
     * A client the adapter built is rebuilt on next use so it carries the new
     * timeout; a client handed to the constructor keeps its own.
     */
    public function setTimeout(int $timeout): static
    {
        $this->timeout = $timeout;

        if ($this->ownsClient) {
            $this->client = null;
            $this->ownsClient = false;
        }

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Set how often a failed request is retried and the first backoff delay
     * in milliseconds.
     */
    public function setRetries(int $retries, ?int $delay = null): static
    {
        $this->retries = max(0, $retries);
        $this->delay = max(0, $delay ?? $this->delay);

        return $this;
    }

    public function getRetries(): int
    {
        return $this->retries;
    }

    /**
     * Headers every request carries, such as credentials.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [];
    }

    /**
     * POST a JSON payload and decode the JSON response.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws Api
     * @throws ClientExceptionInterface
     */
    protected function post(string $url, array $payload): array
    {
        $request = $this->requests()->json(Method::POST, $url, $payload, $this->headers());

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->client()->sendRequest($request);
                $body = (string) $response->getBody();

                if ($response->getStatusCode() >= 400) {
                    throw $this->error($response, $body);
                }

                $json = json_decode($body, true);
                if (! \is_array($json) || array_is_list($json)) {
                    throw new Api('Invalid JSON response', $this->getName(), $response->getStatusCode(), body: substr($body, 0, static::ERROR_BYTES));
                }

                /** @var array<string, mixed> $json */
                return $json;
            } catch (Api|ClientExceptionInterface $error) {
                if (! $this->retry($error, $attempt)) {
                    throw $error;
                }
            }
        }
    }

    /**
     * POST a JSON payload and pass each server-sent event to $listener as its
     * event name and decoded JSON data. Events whose data is not a JSON
     * object, such as "[DONE]", are skipped.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(string, array<string, mixed>): void  $listener
     *
     * @throws Api
     * @throws ClientExceptionInterface
     */
    protected function events(string $url, array $payload, callable $listener): void
    {
        $request = $this->requests()->json(Method::POST, $url, $payload, $this->headers() + ['accept' => 'text/event-stream']);

        for ($attempt = 0; ; $attempt++) {
            $started = false;
            $buffer = '';
            $raw = '';

            $dispatch = function (string $block) use ($listener, &$started): void {
                $name = '';
                $data = [];
                foreach (explode("\n", $block) as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $data[] = ltrim(substr($line, 5), ' ');
                    } elseif (str_starts_with($line, 'event:')) {
                        $name = trim(substr($line, 6));
                    }
                }

                if ($data === []) {
                    return;
                }

                $json = json_decode(implode("\n", $data), true);
                if (! \is_array($json) || array_is_list($json)) {
                    return;
                }

                $started = true;
                /** @var array<string, mixed> $json */
                $listener($name, $json);
            };

            $sink = function (string $chunk) use (&$buffer, &$raw, $dispatch): void {
                if (\strlen($raw) < static::ERROR_BYTES) {
                    $raw .= substr($chunk, 0, static::ERROR_BYTES - \strlen($raw));
                }

                $buffer = str_replace("\r\n", "\n", $buffer.$chunk);
                while (($end = strpos($buffer, "\n\n")) !== false) {
                    $block = substr($buffer, 0, $end);
                    $buffer = substr($buffer, $end + 2);
                    $dispatch($block);
                }

                if (\strlen($buffer) > static::EVENT_BYTES) {
                    throw new Api('Server-sent event exceeds '.static::EVENT_BYTES.' bytes', $this->getName());
                }
            };

            try {
                $response = $this->client()->stream($request, $sink);

                if ($response->getStatusCode() >= 400) {
                    throw $this->error($response, $raw);
                }

                if (trim($buffer) !== '') {
                    $dispatch(str_replace("\r\n", "\n", $buffer));
                }

                return;
            } catch (Api|ClientExceptionInterface $error) {
                if ($started || ! $this->retry($error, $attempt)) {
                    throw $error;
                }
            }
        }
    }

    /**
     * Map an error response to an exception.
     */
    protected function error(ResponseInterface $response, string $body): Api
    {
        $status = $response->getStatusCode();
        [$type, $message] = $this->describe($body);
        $message = ucfirst($this->getName())." API error ({$status}".($type !== '' ? ", {$type}" : '')."): {$message}";

        $retryAfter = null;
        $header = $response->getHeaderLine('retry-after');
        if ($header !== '' && is_numeric($header)) {
            $retryAfter = (float) $header;
        }

        $retryable = \in_array($status, [408, 409, 429], true) || $status >= 500;
        $class = match (true) {
            $status === 429 => RateLimit::class,
            $status === 401, $status === 403 => Authentication::class,
            default => Api::class,
        };

        return new $class($message, $this->getName(), $status, $type, $retryable, $retryAfter, $body);
    }

    /**
     * Error type and message from a provider error body. Handles the common
     * {"error": {"type"|"code"|"status", "message"}} and {"error": "message"}
     * shapes; adapters override it for others.
     *
     * @return array{0: string, 1: string}
     */
    protected function describe(string $body): array
    {
        $json = json_decode($body, true);
        if (! \is_array($json)) {
            return ['', trim($body) !== '' ? trim($body) : 'Unknown error'];
        }

        $error = $json['error'] ?? $json;
        if (\is_string($error)) {
            return ['', $error];
        }
        if (! \is_array($error)) {
            return ['', 'Unknown error'];
        }

        $type = '';
        foreach (['type', 'code', 'status'] as $key) {
            if (isset($error[$key]) && \is_scalar($error[$key]) && (string) $error[$key] !== '') {
                $type = (string) $error[$key];
                break;
            }
        }
        $message = isset($error['message']) && \is_string($error['message']) ? $error['message'] : 'Unknown error';

        return [$type, $message];
    }

    /**
     * Whether to retry after $error on attempt $attempt (zero based); waits
     * the backoff delay before returning true.
     */
    protected function retry(Api|ClientExceptionInterface $error, int $attempt): bool
    {
        if ($attempt >= $this->retries) {
            return false;
        }

        if ($error instanceof Api && ! $error->retryable) {
            return false;
        }

        $delay = $this->delay * (1 << min($attempt, 16));
        $delay += random_int(0, intdiv($delay, 4));
        if ($error instanceof Api && $error->retryAfter !== null) {
            $delay = max($delay, (int) ($error->retryAfter * 1000));
        }

        $this->wait(min($delay, static::MAX_DELAY));

        return true;
    }

    /**
     * Sleep for $milliseconds; yields the coroutine inside Swoole.
     */
    protected function wait(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        if (\extension_loaded('swoole') && Coroutine::getCid() > 0) {
            Coroutine::sleep($milliseconds / 1000);

            return;
        }

        usleep($milliseconds * 1000);
    }

    /**
     * The HTTP client: the one handed to the constructor, or one built on
     * first use.
     */
    protected function client(): ClientInterface&StreamingClientInterface
    {
        if ($this->client === null) {
            $this->client = $this->defaultClient();
            $this->ownsClient = true;
        }

        return $this->client;
    }

    /**
     * Inside a coroutine, a pool of keep-alive Swoole clients shared by every
     * request this adapter makes; elsewhere a single keep-alive cURL client.
     * A request waits for a free pooled connection as long as it would wait
     * for a response, so a busy pool delays a call rather than failing it.
     */
    protected function defaultClient(): ClientInterface&StreamingClientInterface
    {
        $timeout = $this->timeout / 1000;

        if (\extension_loaded('swoole') && Coroutine::getCid() > 0) {
            return new HttpClientPool(new Connections(
                new SwoolePoolAdapter(),
                'agents.'.$this->getName(),
                static::POOL_SIZE,
                static fn () => new HttpClient((new SwooleAdapter())->withConnectionReuse()->withTimeout($timeout)),
                timeout: $timeout,
            ));
        }

        return new HttpClient((new CurlAdapter())->withConnectionReuse()->withTimeout($timeout));
    }

    protected function requests(): Factory
    {
        return $this->requests ??= new Factory();
    }

    /**
     * Decode a tool call's JSON arguments; anything but an object becomes [].
     *
     * @return array<string, mixed>
     */
    protected function arguments(string $json): array
    {
        return $this->map(json_decode($json === '' ? '{}' : $json, true));
    }

    /**
     * A decoded JSON object as a string-keyed map; anything else becomes [].
     *
     * @return array<string, mixed>
     */
    protected function map(mixed $value): array
    {
        if (! \is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (! \is_string($key)) {
                return [];
            }
            $map[$key] = $item;
        }

        return $map;
    }

    /**
     * Tool arguments as a JSON object, keeping {} for an empty map.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|\stdClass
     */
    protected function object(array $arguments): array|\stdClass
    {
        return $arguments === [] ? new \stdClass() : $arguments;
    }
}
