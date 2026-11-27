<?php

declare(strict_types=1);

namespace Utopia\Distribution\Store;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Distribution\Account;
use Utopia\Distribution\App;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\RateLimited;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Issue;
use Utopia\Distribution\JWT;
use Utopia\Distribution\Store;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;

class Apple extends Store
{
    public const string NAME = 'apple';

    public const string ENDPOINT = 'https://api.appstoreconnect.apple.com';

    public const string AUDIENCE = 'appstoreconnect-v1';

    public const int TOKEN_LIFETIME = 1200;

    public const int TOKEN_REFRESH = 900;

    public const int TOKEN_SKEW = 60;

    private const string PATH_APPS = '/v1/apps';

    private const string PATH_BUILDS = '/v1/builds';

    private const string FIELDS_APPS = 'name,bundleId,sku,primaryLocale';

    private const string FIELDS_BUILDS = 'version';

    private const string SORT_BUILDS = '-uploadedDate';

    private const int LIMIT = 200;

    private const string HEADER_RATE_LIMIT = 'X-Rate-Limit';

    private const string RATE_REMAINING = 'user-hour-rem';

    private const int RATE_WINDOW = 3600;

    private const string CODE_AGREEMENTS = 'FORBIDDEN.REQUIRED_AGREEMENTS_MISSING_OR_EXPIRED';

    private const string CODE_PATH = 'PATH_ERROR';

    private const string CODE_STATE = 'STATE_ERROR';

    private readonly JWT $jwt;

    private readonly Factory $factory;

    private ?string $token = null;

    private int $minted = 0;

    private ?int $exhausted = null;

    public function __construct(
        private readonly Credential\Apple $credential,
        ClientInterface $client,
        string $endpoint = self::ENDPOINT,
    ) {
        parent::__construct($client, $endpoint);

        $this->jwt = new JWT($credential->privateKey, JWT::ES256, $credential->keyId);
        $this->factory = new Factory();
    }

    #[\Override]
    public function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function account(): Account
    {
        $this->get(self::PATH_APPS, ['limit' => 1]);

        return new Account($this->credential->issuerId);
    }

    #[\Override]
    public function app(string $identifier): App
    {
        $body = $this->get(self::PATH_APPS, [
            'filter' => ['bundleId' => $identifier],
            'fields' => ['apps' => self::FIELDS_APPS],
            'limit' => self::LIMIT,
        ]);

        foreach ($this->resources($body) as $resource) {
            $id = $resource['id'] ?? null;

            if (\is_string($id) && $id !== '' && $this->attribute($resource, 'bundleId') === $identifier) {
                return new App($id, $identifier, $this->attribute($resource, 'name'));
            }
        }

        throw new NotFound("App Store Connect has no app with bundle id \"{$identifier}\"");
    }

    #[\Override]
    public function latestVersionCode(string $identifier): int
    {
        $app = $this->app($identifier);

        $body = $this->get(self::PATH_BUILDS, [
            'filter' => ['app' => $app->id],
            'sort' => self::SORT_BUILDS,
            'limit' => self::LIMIT,
            'fields' => ['builds' => self::FIELDS_BUILDS],
        ]);

        $latest = 0;

        foreach ($this->resources($body) as $resource) {
            $version = $this->attribute($resource, 'version');

            if ($version !== null && ctype_digit($version)) {
                $latest = max($latest, (int) $version);
            }
        }

        return $latest;
    }

    protected function now(): int
    {
        return time();
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<mixed>
     */
    private function get(string $path, array $query): array
    {
        $response = $this->dispatch($path, $query);

        if ($response->getStatusCode() === 401) {
            $this->token = null;
            $response = $this->dispatch($path, $query);
        }

        $status = $response->getStatusCode();
        $contents = (string) $response->getBody();
        $body = $this->decode($contents);

        if ($status < 200 || $status >= 300) {
            throw $this->failure($status, $body, $contents);
        }

        if ($body === null) {
            throw new Unavailable("App Store Connect returned {$status} without a JSON object");
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function dispatch(string $path, array $query): ResponseInterface
    {
        $this->throttle();

        $response = $this->send($this->factory->query(Method::GET, $this->endpoint . $path, $query, [
            Header::AUTHORIZATION => 'Bearer ' . $this->token(),
            Header::ACCEPT => ContentType::JSON,
        ]));

        $this->observe($response);

        return $response;
    }

    private function token(): string
    {
        $now = $this->now();

        if ($this->token === null || $now - $this->minted >= self::TOKEN_REFRESH) {
            $issued = $now - self::TOKEN_SKEW;
            $this->token = $this->jwt->encode([
                'iss' => $this->credential->issuerId,
                'iat' => $issued,
                'exp' => $issued + self::TOKEN_LIFETIME,
                'aud' => self::AUDIENCE,
            ]);
            $this->minted = $now;
        }

        return $this->token;
    }

    private function throttle(): void
    {
        if ($this->exhausted === null) {
            return;
        }

        if ($this->now() - $this->exhausted >= self::RATE_WINDOW) {
            $this->exhausted = null;

            return;
        }

        throw new RateLimited('App Store Connect hourly request allowance for this key is spent');
    }

    private function observe(ResponseInterface $response): void
    {
        $remaining = $this->remaining($response->getHeaderLine(self::HEADER_RATE_LIMIT));

        if ($remaining !== null) {
            $this->exhausted = $remaining === 0 ? $this->now() : null;
        }
    }

    private function remaining(string $header): ?int
    {
        foreach (explode(';', $header) as $entry) {
            $pair = explode(':', $entry, 2);

            if (\count($pair) === 2 && trim($pair[0]) === self::RATE_REMAINING) {
                $value = trim($pair[1]);

                return ctype_digit($value) ? (int) $value : null;
            }
        }

        return null;
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $contents): ?array
    {
        $body = json_decode($contents, true);

        return \is_array($body) ? $body : null;
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<array<mixed>>
     */
    private function resources(array $body): array
    {
        $data = $body['data'] ?? null;

        if (!\is_array($data)) {
            throw new Unavailable('App Store Connect returned a response without a data array');
        }

        return $this->objects($data);
    }

    /**
     * @return list<array<mixed>>
     */
    private function objects(mixed $values): array
    {
        $objects = [];

        foreach (\is_array($values) ? $values : [] as $value) {
            if (\is_array($value)) {
                $objects[] = $value;
            }
        }

        return $objects;
    }

    /**
     * @param array<mixed> $resource
     */
    private function attribute(array $resource, string $name): ?string
    {
        $attributes = $resource['attributes'] ?? null;
        $value = \is_array($attributes) ? ($attributes[$name] ?? null) : null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<mixed>|null $body
     */
    private function failure(int $status, ?array $body, string $contents): Exception
    {
        $errors = $this->objects($body['errors'] ?? null);
        $code = $this->text($errors[0] ?? [], 'code') ?? '';
        $issues = $this->issues($errors);

        $message = "App Store Connect returned {$status}";
        if ($issues !== []) {
            $message .= " {$issues[0]->code}: {$issues[0]->message}";
        }

        return match (true) {
            $status === 401 => new Unauthorized($message, $issues, credential: true, body: $contents),
            $status === 403 => new Unauthorized($message, $issues, body: $contents),
            $status === 404 && str_starts_with($code, self::CODE_PATH) => new Invalid($message, $issues, body: $contents),
            $status === 404, $status === 410 => new NotFound($message, $issues, body: $contents),
            $status === 409 && str_starts_with($code, self::CODE_STATE) => new Conflict($message, $issues, body: $contents),
            $status === 429 => new RateLimited($message, $issues, body: $contents),
            $status >= 400 && $status < 500 => new Invalid($message, $issues, body: $contents),
            default => new Unavailable($message, $issues, body: $contents),
        };
    }

    /**
     * @param list<array<mixed>> $errors
     *
     * @return list<Issue>
     */
    private function issues(array $errors): array
    {
        $issues = [];

        foreach ($errors as $error) {
            $issue = $this->issue($error);

            if ($issue instanceof Issue) {
                $issues[] = $issue;
            }

            $meta = $error['meta'] ?? null;
            $associated = \is_array($meta) ? ($meta['associatedErrors'] ?? null) : null;

            foreach ($this->objects($associated) as $nested) {
                array_push($issues, ...$this->issues($this->objects($nested)));
            }
        }

        return $issues;
    }

    /**
     * @param array<mixed> $error
     */
    private function issue(array $error): ?Issue
    {
        $code = $this->text($error, 'code');

        if ($code === null) {
            return null;
        }

        $source = $error['source'] ?? null;
        $pointer = \is_array($source) ? $this->text($source, 'pointer') : null;

        return new Issue(
            $code,
            $this->text($error, 'detail') ?? $this->text($error, 'title') ?? $code,
            $pointer,
            str_starts_with($code, self::CODE_AGREEMENTS),
            $this->text($error, 'id'),
        );
    }

    /**
     * @param array<mixed> $values
     */
    private function text(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
