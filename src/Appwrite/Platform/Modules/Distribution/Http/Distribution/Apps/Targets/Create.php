<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets;

use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Provider;
use Appwrite\Distribution\VersionStrategy;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\JSON;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public const array CONFIGURATION_KEYS = [
        'scheme',
        'workspace',
        'project',
        'configuration',
        'flavor',
        'gradleTask',
        'xcode',
        'flutter',
        'node',
        'java',
    ];

    public static function getName(): string
    {
        return 'createTarget';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/apps/:appId/targets')
            ->desc('Create target')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].targets.[targetId].create')
            ->label('audits.event', 'distribution.target.create')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'targets',
                name: 'createTarget',
                description: <<<EOT
                Create a new target for an app. A target is one platform the app ships to, with its bundle ID or package name, store connection, signing identity and build settings. An app has at most one target per platform.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DISTRIBUTION_TARGET,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('targetId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'Target ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('platform', '', new WhiteList(Platform::values(), true), 'Platform the target builds for. The app framework must support it.', enum: new Enum(name: 'DistributionPlatform'))
            ->param('identifier', '', new Text(256, 1), 'Bundle ID on iOS or package name on Android. Max length: 256 chars.')
            ->param('connectionId', '', new Text(Database::LENGTH_KEY, 0), 'ID of the store connection used for submissions. Its provider must match the platform: Apple for iOS, Google for Android.', true)
            ->param('signingIdentityId', '', new Text(Database::LENGTH_KEY, 0), 'ID of the signing identity used to sign builds. Its platform must match the target platform.', true)
            ->param('track', '', new Text(64, 0), 'Default store track for automatic submissions. iOS accepts testflight, testflight-external and production; Android accepts any track of the app.', true)
            ->param('automatic', false, new Boolean(), 'Whether successful Git builds are submitted to the default track automatically.', true)
            ->param('versionStrategy', VersionStrategy::Automatic->value, new WhiteList(VersionStrategy::values(), true), 'How build numbers are allocated.', true, enum: new Enum(name: 'DistributionVersionStrategy'))
            ->param('installCommand', '', new Text(16384, 0), 'Command that installs dependencies. Leave empty to use the framework default.', true)
            ->param('buildCommand', '', new Text(16384, 0), 'Command that builds the app. Leave empty to use the framework default.', true)
            ->param('outputPath', '', new Text(16384, 0), 'Path of the built binary. Leave empty to use the framework default.', true)
            ->param('configuration', [], new JSON(), 'Platform build settings as an object of strings. Allowed keys: ' . \implode(', ', self::CONFIGURATION_KEYS) . '.', true)
            ->param('specification', '', new WhiteList(['', ...\array_keys(Config::getParam('distribution')['specifications'])], true), 'Build specification key. Leave empty to use the instance default.', true)
            ->param('timeout', 0, new Range(0, (int) System::getEnv('_APP_DISTRIBUTION_BUILD_TIMEOUT', '3600')), 'Build timeout in seconds. Use 0 for the instance default.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    /**
     * @param array<array-key, mixed>|string $configuration
     */
    public function action(
        string $appId,
        string $targetId,
        string $platform,
        string $identifier,
        string $connectionId,
        string $signingIdentityId,
        string $track,
        bool $automatic,
        string $versionStrategy,
        string $installCommand,
        string $buildCommand,
        string $outputPath,
        array|string $configuration,
        string $specification,
        int $timeout,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $framework = $app->getAttribute('framework');

        if (!\array_key_exists($platform, Config::getParam('distribution')['frameworks'][$framework] ?? [])) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Framework '{$framework}' does not support the '{$platform}' platform.");
        }

        $connection = $this->getConnection($dbForProject, $connectionId, $platform);
        $signingIdentity = $this->getSigningIdentity($dbForProject, $signingIdentityId, $platform);
        $this->assertTrack($track, $platform);
        $configuration = $this->parseConfiguration($configuration);

        $targetId = $targetId === 'unique()' ? Id::unique() : $targetId;

        try {
            $target = $dbForProject->createDocument('distributionTargets', new Document([
                '$id' => $targetId,
                'appId' => $app->getId(),
                'appInternalId' => $app->getSequence(),
                'platform' => $platform,
                'identifier' => $identifier,
                'connectionId' => $connection->getId(),
                'connectionInternalId' => $connection->getSequence() ?? '',
                'signingIdentityId' => $signingIdentity->getId(),
                'signingIdentityInternalId' => $signingIdentity->getSequence() ?? '',
                'track' => $track,
                'automatic' => $automatic,
                'versionStrategy' => $versionStrategy,
                'versionCode' => 0,
                'installCommand' => $installCommand,
                'buildCommand' => $buildCommand,
                'outputPath' => $outputPath,
                'configuration' => $configuration,
                'specification' => $specification,
                'timeout' => $timeout,
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::DISTRIBUTION_TARGET_ALREADY_EXISTS);
        }

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('targetId', $target->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($target, Response::MODEL_DISTRIBUTION_TARGET);
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

    private function assertTrack(string $track, string $platform): void
    {
        if ($track === '' || $platform !== Platform::Ios->value) {
            return;
        }

        $tracks = Config::getParam('distribution')['tracks'][Provider::Apple->value];

        if (!\in_array($track, $tracks, true)) {
            throw new Exception(Exception::DISTRIBUTION_TRACK_INVALID, "Track '{$track}' is not an App Store track. Use one of: " . \implode(', ', $tracks) . '.');
        }
    }

    /**
     * @param array<array-key, mixed>|string $configuration
     * @return array<string, string>
     */
    private function parseConfiguration(array|string $configuration): array
    {
        if (\is_string($configuration)) {
            $configuration = \json_decode($configuration, true);
        }

        if (!\is_array($configuration)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Configuration must be an object.');
        }

        foreach ($configuration as $key => $value) {
            if (!\in_array($key, self::CONFIGURATION_KEYS, true)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Configuration key '{$key}' is not supported. Allowed keys: " . \implode(', ', self::CONFIGURATION_KEYS) . '.');
            }

            if (!\is_string($value)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Configuration value of '{$key}' must be a string.");
            }
        }

        return $configuration;
    }
}
