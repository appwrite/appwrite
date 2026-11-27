<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Builds;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Build\ReferenceType;
use Appwrite\Distribution\Build\Trigger;
use Appwrite\Distribution\Builds;
use Appwrite\Distribution\Checks;
use Appwrite\Distribution\Stores;
use Appwrite\Distribution\Submissions;
use Appwrite\Distribution\VersionStrategy;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationRepositories;
use Appwrite\Vcs\InstallationTokens;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\RepositoryNotFound;

class Create extends Action
{
    use HTTP;

    private const int LOCK_TTL = 120;

    private const float LOCK_TIMEOUT = 5.0;

    private const int MESSAGE_LENGTH = 255;

    private const int HASH_MIN = 7;

    private const int HASH_MAX = 64;

    public static function getName(): string
    {
        return 'createBuild';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/apps/:appId/builds')
            ->desc('Create build')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].builds.[buildId].create')
            ->label('audits.event', 'distribution.build.create')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'builds',
                name: 'createBuild',
                description: <<<EOT
                Start a build of a target from the app's repository. The reference is resolved to a commit before the build is queued: the latest commit of a branch, a commit hash, or a tag. The build runs in the background, and its status moves from waiting through building and signing to ready. Sending the same key again returns the existing build instead of starting a second one.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_DISTRIBUTION_BUILD,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('targetId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'ID of the target to build.', false, ['dbForProject'])
            ->param('type', ReferenceType::Branch->value, new WhiteList(ReferenceType::values(), true), 'How the reference is resolved: branch, commit or tag.', true, enum: new Enum(name: 'DistributionReferenceType'))
            ->param('reference', '', new Text(255, 0), 'Branch name, commit hash or tag name to build. Leave empty with the branch type to build the production branch of the app.', true)
            ->param('versionName', '', new Text(64, 0), 'Version name of the build, such as 1.4.0. Leave empty to keep the one in the project files.', true)
            ->param('versionCode', null, new Nullable(new Range(1, Builds::MAX_VERSION_CODE)), 'Version code, or build number on iOS. Required when the target uses the manual version strategy; ignored otherwise.', true)
            ->param('releaseId', '', fn (Database $dbForProject) => new Text($dbForProject->getMaxUidLength(), 0), 'ID of the release the build belongs to.', true, ['dbForProject'])
            ->param('key', '', new Text(128, 0), 'Idempotency key. A second request with the same key for the same target returns the existing build. Max length: 128 chars.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('distributionBuilds')
            ->inject('distributionStores')
            ->inject('vcsFactory')
            ->inject('installationTokens')
            ->inject('installationRepositories')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $targetId,
        string $type,
        string $reference,
        string $versionName,
        ?int $versionCode,
        string $releaseId,
        string $key,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Event $queueForEvents,
        Builds $distributionBuilds,
        Stores $distributionStores,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        InstallationRepositories $installationRepositories,
        callable $locks,
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        if ($app->getAttribute('enabled', true) === false) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'The app is disabled. Enable it, then start a build.');
        }

        if ($app->getAttribute('installationId', '') === '' || $app->getAttribute('providerRepositoryId', '') === '') {
            throw new Exception(Exception::DISTRIBUTION_REPOSITORY_MISSING);
        }

        $target = $this->getChild($dbForProject, 'distributionTargets', $targetId, $app, Exception::DISTRIBUTION_TARGET_NOT_FOUND);
        $release = $releaseId === '' ? new Document() : $this->getChild($dbForProject, 'distributionReleases', $releaseId, $app, Exception::DISTRIBUTION_RELEASE_NOT_FOUND);

        $manual = $target->getAttribute('versionStrategy', VersionStrategy::Automatic->value) === VersionStrategy::Manual->value;

        if ($manual && $versionCode === null) {
            throw new Exception(Exception::DISTRIBUTION_VERSION_INVALID, 'The target uses the manual version strategy, so every build needs a version code.');
        }

        $buildId = $key === '' ? Id::unique() : 'b' . \substr(\hash('sha256', "{$target->getId()}:{$key}"), 0, 35);

        if ($key !== '') {
            $existing = $this->getExisting($dbForProject, $app, $buildId);

            if (!$existing->isEmpty()) {
                $queueForEvents->reset();
                $response->dynamic($existing, Response::MODEL_DISTRIBUTION_BUILD);
                return;
            }
        }

        $length = \strlen($reference);

        if ($type === ReferenceType::Commit->value && ($length < self::HASH_MIN || $length > self::HASH_MAX || !\ctype_xdigit($reference))) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Pass a commit hash of ' . self::HASH_MIN . ' to ' . self::HASH_MAX . ' hexadecimal characters.');
        }

        $installation = $dbForPlatform->getDocument('installations', (string) $app->getAttribute('installationId', ''));

        if ($installation->isEmpty() || $installation->getAttribute('projectId') !== $project->getId()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $installation = $installationTokens->refreshForInstallation($installation, $dbForPlatform, $vcsFactory);
        $vcs = $vcsFactory->fromInstallation($installation);

        if (!$vcs->supportsRepositoryArchives()) {
            throw new Exception(Exception::DISTRIBUTION_REPOSITORY_MISSING, 'This Git provider offers no repository archives, which Distribution builds from. Connect a GitHub, GitLab, Gitea or Bitbucket repository.');
        }

        [$owner, $repository] = $installationRepositories->resolve($vcs, $installation, (string) $app->getAttribute('providerRepositoryId', ''));

        $build = new Document([
            '$id' => $buildId,
            'type' => Trigger::Manual->value,
            ...$this->resolve($vcs, $owner, $repository, $type, $reference, $app),
            'versionName' => $versionName,
            ...($manual ? ['versionCode' => $versionCode] : []),
            ...($release->isEmpty() ? [] : ['releaseId' => $release->getId(), 'releaseInternalId' => (string) $release->getSequence()]),
        ]);

        try {
            $build = $distributionBuilds->create(
                $app,
                $target,
                $build,
                $vcs,
                $owner,
                $repository,
                $manual ? null : $this->latest($locks, $distributionStores, $dbForProject, $target),
                new Checks($dbForProject, $distributionStores),
            );
        } catch (Duplicate) {
            $queueForEvents->reset();
            $response->dynamic($this->getExisting($dbForProject, $app, $buildId), Response::MODEL_DISTRIBUTION_BUILD);
            return;
        }

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('buildId', $build->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($build, Response::MODEL_DISTRIBUTION_BUILD);
    }

    private function getChild(Database $dbForProject, string $collection, string $id, Document $app, string $error): Document
    {
        $document = $dbForProject->getDocument($collection, $id);

        if ($document->isEmpty() || $document->getAttribute('appInternalId') !== $app->getSequence()) {
            throw new Exception($error);
        }

        return $document;
    }

    private function getExisting(Database $dbForProject, Document $app, string $buildId): Document
    {
        $build = $dbForProject->getDocument('distributionBuilds', $buildId);

        if ($build->isEmpty() || $build->getAttribute('appInternalId') !== $app->getSequence()) {
            return new Document();
        }

        return $build;
    }

    /**
     * Only the commit hash is stored, so a branch that moves later still builds the commit resolved here.
     *
     * @return array<string, string>
     */
    private function resolve(Git $vcs, string $owner, string $name, string $type, string $reference, Document $app): array
    {
        $type = ReferenceType::from($type);
        $reference = $type === ReferenceType::Branch ? ($reference ?: (string) $app->getAttribute('providerBranch', '')) : $reference;

        if ($reference === '') {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Pass a reference to build from a {$type->value}.");
        }

        try {
            $commit = match ($type) {
                ReferenceType::Branch => $vcs->getLatestCommit($owner, $name, $reference),
                ReferenceType::Commit => $vcs->getCommit($owner, $name, $reference),
                ReferenceType::Tag => \in_array($reference, $vcs->listTags($owner, $name, $reference), true) ? $vcs->getCommit($owner, $name, $reference) : [],
            };
        } catch (RepositoryNotFound) {
            $commit = [];
        } catch (\Exception $error) {
            // Most providers answer a missing commit with a plain exception, not RepositoryNotFound.
            if ($type !== ReferenceType::Commit) {
                throw $error;
            }

            $commit = [];
        }

        $hash = (string) ($commit['commitHash'] ?? '');

        if ($hash === '') {
            throw new Exception(Exception::DISTRIBUTION_REFERENCE_NOT_FOUND, "The {$type->value} '{$reference}' was not found in {$owner}/{$name}.");
        }

        return [
            'providerBranch' => $type === ReferenceType::Branch ? $reference : '',
            'providerTag' => $type === ReferenceType::Tag ? $reference : '',
            'providerBranchUrl' => $type === ReferenceType::Branch ? $vcs->getBranchUrl($owner, $name, $reference) : '',
            'providerCommitHash' => $hash,
            'providerCommitMessage' => \mb_strimwidth((string) ($commit['commitMessage'] ?? ''), 0, self::MESSAGE_LENGTH, '...'),
            'providerCommitAuthor' => (string) ($commit['commitAuthor'] ?? ''),
            'providerCommitAuthorUrl' => (string) ($commit['commitAuthorUrl'] ?? ''),
            'providerCommitUrl' => (string) ($commit['commitUrl'] ?? ''),
        ];
    }

    /**
     * The store's latest version code, so an automatic code never repeats one the store already has.
     * The lock is the one submissions take, because opening a Google edit invalidates the others.
     */
    private function latest(callable $locks, Stores $stores, Database $dbForProject, Document $target): ?int
    {
        $connectionId = (string) $target->getAttribute('connectionId', '');

        if ($connectionId === '') {
            return null;
        }

        try {
            $connection = $dbForProject->getDocument('distributionConnections', $connectionId);
            $identifier = (string) $target->getAttribute('identifier', '');

            if ($connection->isEmpty() || Submissions::isContended($dbForProject, $connection, $identifier)) {
                return null;
            }

            return $locks(
                Submissions::storeLock($connection, $identifier),
                self::LOCK_TTL,
                fn (): int => $stores->connection($connection)->latestVersionCode($identifier),
                self::LOCK_TIMEOUT,
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
