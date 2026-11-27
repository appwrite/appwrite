<?php

namespace Appwrite\Distribution;

use Appwrite\Extend\Exception;
use Psr\Http\Client\ClientInterface;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Distribution\Account;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\RateLimited;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Issue;
use Utopia\Distribution\Release;
use Utopia\Distribution\Store;
use Utopia\Distribution\Upload;
use Utopia\Storage\Device;

readonly class Stores
{
    private const int CONNECT_TIMEOUT = 5;

    private const int REQUEST_TIMEOUT = 30;

    private const string TOKEN_PATH = '/token';

    private const array REUPLOADS = ['editExpired', 'editAlreadyCommitted', 'editNotFound', 'uploadSessionEnded'];

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

    public static function provider(Platform $platform): Provider
    {
        return match ($platform) {
            Platform::Ios => Provider::Apple,
            Platform::Android => Provider::Google,
        };
    }

    public static function isExclusive(Document $connection): bool
    {
        return Provider::tryFrom((string) $connection->getAttribute('provider', '')) === Provider::Google;
    }

    public static function identity(Document $connection): string
    {
        $account = $connection->getAttribute('account', []);
        $email = \is_array($account) && \is_string($account['email'] ?? null) ? $account['email'] : '';

        return $email !== '' ? $email : "{$connection->getSequence()}:{$connection->getId()}";
    }

    public static function isReupload(NotFound $exception): bool
    {
        return \array_any($exception->getIssues(), static fn (Issue $issue): bool => \in_array($issue->code, self::REUPLOADS, true));
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

    /**
     * @return list<string>
     */
    public function tracks(Provider $provider): array
    {
        return Config::getParam('distribution')['tracks'][$provider->value] ?? [];
    }

    public function release(Document $submission, Document $release, Document $target, Document $artifact): Release
    {
        $provider = Provider::tryFrom((string) $submission->getAttribute('provider', ''));
        $track = (string) $submission->getAttribute('track', '');

        if ($provider === null || !\in_array($track, $this->tracks($provider), true)) {
            throw new Exception(Exception::DISTRIBUTION_TRACK_INVALID, "Track '{$track}' is not a track of the submission's store.");
        }

        try {
            return new Release(
                identifier: (string) $target->getAttribute('identifier', ''),
                track: $track,
                version: $this->version($artifact, (string) $release->getAttribute('version', '')),
                build: $this->build($artifact),
                fraction: (float) $submission->getAttribute('rollout', 1.0),
                notes: $this->notes($release),
                automatic: (bool) $submission->getAttribute('automatic', true),
            );
        } catch (Invalid $invalid) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $invalid->getMessage(), previous: $invalid);
        }
    }

    public function upload(Document $artifact, Device $deviceForDistribution): Upload
    {
        $path = (string) $artifact->getAttribute('path', '');
        $build = $this->build($artifact);

        try {
            return new Upload(
                device: $deviceForDistribution,
                path: $path,
                name: (string) ($artifact->getAttribute('name') ?: \basename($path)),
                size: (int) $artifact->getAttribute('size', 0),
                identifier: (string) $artifact->getAttribute('identifier', ''),
                version: $this->version($artifact, $build),
                build: $build,
                platform: (string) $artifact->getAttribute('platform', ''),
            );
        } catch (Invalid $invalid) {
            throw new Exception(Exception::DISTRIBUTION_ARTIFACT_INVALID, $invalid->getMessage(), previous: $invalid);
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

    private function version(Document $artifact, string $fallback): string
    {
        return (string) ($artifact->getAttribute('versionName') ?: $fallback);
    }

    private function build(Document $artifact): string
    {
        return match (Platform::tryFrom((string) $artifact->getAttribute('platform', ''))) {
            Platform::Android => (string) $artifact->getAttribute('versionCode', ''),
            Platform::Ios => (string) $artifact->getAttribute('buildNumber', ''),
            null => '',
        };
    }

    /**
     * @return array<string, string>
     */
    private function notes(Document $release): array
    {
        $notes = $release->getAttribute('notes', []);

        if (!\is_array($notes)) {
            return [];
        }

        return \array_filter(
            $notes,
            static fn (mixed $text, mixed $locale): bool => \is_string($locale) && \is_string($text) && $text !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
