<?php

namespace Appwrite\Distribution;

use Appwrite\Extend\Exception;
use Psr\Http\Client\ClientInterface;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Database\Document;
use Utopia\Distribution\Account;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\RateLimited;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Store;

readonly class Stores
{
    private const int CONNECT_TIMEOUT = 5;

    private const int REQUEST_TIMEOUT = 30;

    private const string TOKEN_PATH = '/token';

    private ClientInterface $client;

    public function __construct(
        private string $appleEndpoint = '',
        private string $googleEndpoint = '',
        bool $production = true,
        ?ClientInterface $client = null,
    ) {
        if ($production && ($appleEndpoint !== '' || $googleEndpoint !== '')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Distribution store endpoint overrides cannot be used in production.');
        }

        $this->client = $client ?? (new Client(new CurlAdapter()))
            ->withConnectTimeout(self::CONNECT_TIMEOUT)
            ->withTimeout(self::REQUEST_TIMEOUT);
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function adapter(Provider $provider, #[\SensitiveParameter] array $credentials): Store
    {
        try {
            return match ($provider) {
                Provider::Apple => new Store\Apple(
                    new Credential\Apple(
                        $this->text($credentials, 'keyId'),
                        $this->text($credentials, 'issuerId'),
                        $this->text($credentials, 'privateKey'),
                    ),
                    $this->client,
                    \rtrim($this->appleEndpoint, '/') ?: Store\Apple::ENDPOINT,
                ),
                Provider::Google => $this->google(new Credential\Google(
                    $this->text($credentials, 'clientEmail'),
                    $this->text($credentials, 'privateKey'),
                    $this->project($credentials),
                )),
            };
        } catch (Invalid $invalid) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, $invalid->getMessage(), previous: $invalid);
        }
    }

    public function connection(Document $connection): Store
    {
        $provider = Provider::tryFrom((string) $connection->getAttribute('provider', ''));
        $credentials = $connection->getAttribute('credentials', []);

        if ($provider === null || !\is_array($credentials)) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, 'The connection has no usable store credentials.');
        }

        return $this->adapter($provider, $credentials);
    }

    public function verify(Store $store): Account
    {
        try {
            return $store->account();
        } catch (Unauthorized|Invalid|NotFound|Conflict $rejected) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, $rejected->getMessage(), previous: $rejected);
        } catch (RateLimited|Unavailable $unavailable) {
            throw new Exception(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $unavailable->getMessage(), previous: $unavailable);
        }
    }

    private function google(Credential\Google $credential): Store\Google
    {
        $endpoint = \rtrim($this->googleEndpoint, '/');

        if ($endpoint === '') {
            return new Store\Google($credential, $this->client);
        }

        return new Store\Google($credential, $this->client, $endpoint, $endpoint . self::TOKEN_PATH);
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function text(#[\SensitiveParameter] array $credentials, string $key): string
    {
        $value = $credentials[$key] ?? null;

        if (!\is_string($value)) {
            throw new Invalid("Store credentials are missing {$key}");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function project(#[\SensitiveParameter] array $credentials): ?string
    {
        $project = $credentials['projectId'] ?? null;

        if ($project !== null && !\is_string($project)) {
            throw new Invalid('Store credentials projectId must be a string');
        }

        return $project;
    }
}
