<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets;

use Appwrite\Distribution\Checks;
use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Provider;
use Appwrite\Distribution\Stores;
use Appwrite\Distribution\VersionStrategy;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateTarget';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/apps/:appId/targets/:targetId')
            ->desc('Update target')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].targets.[targetId].update')
            ->label('audits.event', 'distribution.target.update')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'targets',
                name: 'updateTarget',
                description: <<<EOT
                Update a target by its unique ID. Only the parameters you pass are changed. Pass an empty connection or signing identity ID to detach it. The platform cannot change.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_TARGET,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('targetId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Target ID.', false, ['dbForProject'])
            ->param('identifier', null, new Nullable(new Text(256, 1)), 'Bundle ID on iOS or package name on Android. Max length: 256 chars.', true)
            ->param('connectionId', null, new Nullable(new Text(Database::LENGTH_KEY, 0)), 'ID of the store connection used for submissions. Its provider must match the platform: Apple for iOS, Google for Android. Pass an empty string to detach it.', true)
            ->param('signingIdentityId', null, new Nullable(new Text(Database::LENGTH_KEY, 0)), 'ID of the signing identity used to sign builds. Its platform must match the target platform. Pass an empty string to detach it.', true)
            ->param('track', null, new Nullable(new Text(64, 0)), 'Default store track for automatic submissions. iOS accepts testflight, testflight-external and production; Android accepts any track of the app.', true)
            ->param('automatic', null, new Nullable(new Boolean()), 'Whether successful Git builds are submitted to the default track automatically.', true)
            ->param('versionStrategy', null, new Nullable(new WhiteList(VersionStrategy::values(), true)), 'How build numbers are allocated.', true, enum: new Enum(name: 'DistributionVersionStrategy'))
            ->param('installCommand', null, new Nullable(new Text(16384, 0)), 'Command that installs dependencies. Pass an empty string to use the framework default.', true)
            ->param('buildCommand', null, new Nullable(new Text(16384, 0)), 'Command that builds the app. Pass an empty string to use the framework default.', true)
            ->param('outputPath', null, new Nullable(new Text(16384, 0)), 'Path of the built binary. Pass an empty string to use the framework default.', true)
            ->param('configuration', null, new Nullable(new JSON()), 'Platform build settings as an object of strings. Replaces the current settings. Allowed keys: ' . \implode(', ', Create::CONFIGURATION_KEYS) . '.', true)
            ->param('specification', null, new Nullable(new WhiteList(['', ...\array_keys(Config::getParam('distribution')['specifications'])], true)), 'Build specification key. Pass an empty string to use the instance default.', true)
            ->param('timeout', null, new Nullable(new Range(0, (int) System::getEnv('_APP_DISTRIBUTION_BUILD_TIMEOUT', '3600'))), 'Build timeout in seconds. Use 0 for the instance default.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    /**
     * @param array<array-key, mixed>|string|null $configuration
     */
    public function action(
        string $appId,
        string $targetId,
        ?string $identifier,
        ?string $connectionId,
        ?string $signingIdentityId,
        ?string $track,
        ?bool $automatic,
        ?string $versionStrategy,
        ?string $installCommand,
        ?string $buildCommand,
        ?string $outputPath,
        array|string|null $configuration,
        ?string $specification,
        ?int $timeout,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $target = $dbForProject->getDocument('distributionTargets', $targetId);

        if ($target->isEmpty() || $target->getAttribute('appInternalId') !== $app->getSequence()) {
            throw new Exception(Exception::DISTRIBUTION_TARGET_NOT_FOUND);
        }

        $attributes = \array_filter([
            'identifier' => $identifier,
            'track' => $track,
            'automatic' => $automatic,
            'versionStrategy' => $versionStrategy,
            'installCommand' => $installCommand,
            'buildCommand' => $buildCommand,
            'outputPath' => $outputPath,
            'specification' => $specification,
            'timeout' => $timeout,
        ], static fn (mixed $value): bool => !\is_null($value));

        if (empty($attributes) && \is_null($connectionId) && \is_null($signingIdentityId) && \is_null($configuration)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'At least one parameter to update is required.');
        }

        $platform = $target->getAttribute('platform');
        $updates = new Document($attributes);

        if (!\is_null($connectionId)) {
            $connection = $this->getConnection($dbForProject, $connectionId, $platform);
            $updates
                ->setAttribute('connectionId', $connection->getId())
                ->setAttribute('connectionInternalId', $connection->getSequence() ?? '');
        }

        if (!\is_null($signingIdentityId)) {
            $signingIdentity = $this->getSigningIdentity($dbForProject, $signingIdentityId, $platform);
            $updates
                ->setAttribute('signingIdentityId', $signingIdentity->getId())
                ->setAttribute('signingIdentityInternalId', $signingIdentity->getSequence() ?? '');
        }

        if (!\is_null($configuration)) {
            $updates->setAttribute('configuration', $this->parseConfiguration($configuration, $platform));
        }

        if (!\is_null($track) || !\is_null($configuration)) {
            $merged = $updates->getAttribute('configuration', $target->getAttribute('configuration', []));
            $this->assertTrack($track ?? (string) $target->getAttribute('track', ''), $platform, \is_array($merged) ? $merged : []);
        }

        $target = $dbForProject->updateDocument('distributionTargets', $target->getId(), $updates);

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('targetId', $target->getId());

        $response->dynamic($target, Response::MODEL_DISTRIBUTION_TARGET);
    }

    private function getConnection(Database $dbForProject, string $connectionId, string $platform): Document
    {
        if ($connectionId === '') {
            return new Document();
        }

        $connection = $dbForProject->getDocument('distributionConnections', $connectionId);

        if ($connection->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_NOT_FOUND);
        }

        $provider = match (Platform::from($platform)) {
            Platform::Ios => Provider::Apple,
            Platform::Android => Provider::Google,
        };

        if ($connection->getAttribute('provider') !== $provider->value) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "A '{$platform}' target needs a '{$provider->value}' connection.");
        }

        return $connection;
    }

    private function getSigningIdentity(Database $dbForProject, string $signingIdentityId, string $platform): Document
    {
        if ($signingIdentityId === '') {
            return new Document();
        }

        $signingIdentity = $dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId);

        if ($signingIdentity->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_NOT_FOUND);
        }

        if ($signingIdentity->getAttribute('platform') !== $platform) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "A '{$platform}' target needs a '{$platform}' signing identity.");
        }

        return $signingIdentity;
    }

    /**
     * @param array<array-key, mixed> $configuration
     */
    private function assertTrack(string $track, string $platform, array $configuration): void
    {
        if ($track === '' || $platform !== Platform::Ios->value) {
            return;
        }

        $tracks = Config::getParam('distribution')['tracks'][Provider::Apple->value];

        if (!\in_array($track, $tracks, true)) {
            throw new Exception(Exception::DISTRIBUTION_TRACK_INVALID, "Track '{$track}' is not an App Store track. Use one of: " . \implode(', ', $tracks) . '.');
        }

        if (Stores::isMissingGroups($track, $configuration)) {
            throw new Exception(Exception::DISTRIBUTION_TRACK_INVALID, Checks::GROUPS_REQUIRED);
        }
    }

    /**
     * @param array<array-key, mixed>|string $configuration
     * @return array<string, string>
     */
    private function parseConfiguration(array|string $configuration, string $platform): array
    {
        if (\is_string($configuration)) {
            $configuration = \json_decode($configuration, true);
        }

        if (!\is_array($configuration)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Configuration must be an object.');
        }

        foreach ($configuration as $key => $value) {
            if (!\in_array($key, Create::CONFIGURATION_KEYS, true)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Configuration key '{$key}' is not supported. Allowed keys: " . \implode(', ', Create::CONFIGURATION_KEYS) . '.');
            }

            if (!\is_string($value)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Configuration value of '{$key}' must be a string.");
            }

            if ($platform !== Platform::Ios->value && \in_array($key, Stores::CONFIGURATION_APPLE, true)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Configuration key '{$key}' applies only to iOS targets.");
            }

            if ($key === Stores::CONFIGURATION_ENCRYPTION && $value !== Stores::ENCRYPTION_EXEMPT) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Configuration value of '{$key}' must be '" . Stores::ENCRYPTION_EXEMPT . "'. Builds that use non-exempt encryption need their export compliance documented in App Store Connect.");
            }
        }

        return $configuration;
    }
}
