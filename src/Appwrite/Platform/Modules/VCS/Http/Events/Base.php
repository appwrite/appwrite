<?php

namespace Appwrite\Platform\Modules\VCS\Http\Events;

use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\Deployment;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Provider;
use Appwrite\Vcs\Resolver;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\VCS\Adapter\Git;

/**
 * Webhook receiver for one VCS provider. Subclasses only name the provider;
 * event headers, signature validation, and payload parsing come from the
 * provider registry and its adapter.
 */
abstract class Base extends Action
{
    use HTTP;
    use Deployment;

    /**
     * Provider key in the `vcs` config registry.
     */
    abstract public static function getProvider(): string;

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/' . static::getProvider() . '/events')
            ->desc('Create event')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->inject('vcs')
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('getProjectDB')
            ->inject('publisherForBuilds')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        Resolver $vcs,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        BuildPublisher $publisherForBuilds,
        array $platform
    ) {
        $this->preprocessEvent($request);

        $provider = $vcs->getProvider(static::getProvider());
        $key = $provider->getKey();

        $event = $request->getHeaderLine($provider->getEventHeader(), '');
        Span::add("vcs.{$key}.event.name", $event);

        $payload = $request->getRawPayload();
        $signature = $request->getHeaderLine($provider->getSignatureHeader(), '');
        $secretKey = $provider->getWebhookSecret();

        $adapter = $vcs->createAdapter($key);

        $valid = empty($secretKey) ? true : $adapter->validateWebhookEvent($payload, $signature, $secretKey);
        Span::add("vcs.{$key}.event.signature.valid", $valid);

        if (!$valid) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, "Invalid webhook payload signature. Please make sure the webhook secret has same value in your " . $provider->getName() . " app and in the " . $provider->getEnvName('WEBHOOK_SECRET') . " environment variable");
        }

        $parsedPayload = $adapter->getEvent($event, $payload);

        match ($event) {
            'installation' => $provider->getAuthType() === Provider::AUTH_APP
                ? $this->handleInstallationEvent($parsedPayload, $dbForPlatform, $authorization, $getProjectDB)
                : null,
            'push' => $this->handlePushEvent($provider, $adapter, $parsedPayload, $vcs, $dbForPlatform, $authorization, $publisherForBuilds, $getProjectDB, $platform),
            'pull_request' => $this->handlePullRequestEvent($provider, $adapter, $parsedPayload, $vcs, $dbForPlatform, $authorization, $publisherForBuilds, $getProjectDB, $platform),
            default => null,
        };

        $response->json($parsedPayload);
    }

    protected function preprocessEvent(Request $request)
    {
        return;
    }

    /**
     * Authenticate the adapter for API calls triggered by this event.
     * App-based providers authenticate with app credentials and the event's
     * installation id; OAuth2-based providers with the tokens of the matching
     * installation. Returns null when no matching installation exists.
     */
    protected function authenticateAdapter(
        Provider $provider,
        Git $adapter,
        Resolver $vcs,
        array $parsedPayload,
        Database $dbForPlatform,
        Authorization $authorization,
    ): ?Git {
        if ($provider->getAuthType() === Provider::AUTH_APP) {
            $adapter->initializeVariables(
                $parsedPayload['installationId'] ?? '',
                $provider->getEnv('PRIVATE_KEY'),
                $provider->getEnv('APP_ID'),
            );

            return $adapter;
        }

        $installation = $authorization->skip(fn () => $dbForPlatform->findOne('installations', [
            Query::equal('provider', [$provider->getKey()]),
            Query::equal('organization', [$parsedPayload['owner'] ?? '']),
        ]));

        if ($installation->isEmpty()) {
            return null;
        }

        return $vcs->getAdapter($installation, $dbForPlatform);
    }

    protected function handleInstallationEvent(
        array $parsedPayload,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
    ) {
        if ($parsedPayload["action"] !== "deleted") {
            return;
        }

        $providerInstallationId = $parsedPayload["installationId"];

        $installationCursor = null;
        do {
            $installationQueries = [
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::limit(1000),
            ];
            if ($installationCursor !== null) {
                $installationQueries[] = Query::cursorAfter($installationCursor);
            }
            $installations = $authorization->skip(fn () => $dbForPlatform->find('installations', $installationQueries));

            foreach ($installations as $installation) {
                $projectId = $installation->getAttribute('projectId', '');
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

                if (!$project->isEmpty()) {
                    $dbForProject = $getProjectDB($project);

                    foreach (['functions', 'sites'] as $collection) {
                        $cursor = null;
                        do {
                            $queries = [
                                Query::equal('installationInternalId', [$installation->getSequence()]),
                                Query::limit(1000),
                            ];
                            if ($cursor !== null) {
                                $queries[] = Query::cursorAfter($cursor);
                            }
                            $resources = $authorization->skip(fn () => $dbForProject->find($collection, $queries));

                            foreach ($resources as $resource) {
                                $authorization->skip(fn () => $dbForProject->updateDocument($collection, $resource->getId(), new Document([
                                    'installationId' => '',
                                    'installationInternalId' => '',
                                    'providerRepositoryId' => '',
                                    'providerBranch' => '',
                                    'providerSilentMode' => false,
                                    'providerRootDirectory' => '',
                                    'repositoryId' => '',
                                    'repositoryInternalId' => '',
                                ])));
                            }

                            $cursor = count($resources) === 1000 ? $resources[array_key_last($resources)] : null;
                        } while ($cursor !== null);
                    }
                }

                $cursor = null;
                do {
                    $queries = [
                        Query::equal('installationInternalId', [$installation->getSequence()]),
                        Query::limit(1000),
                    ];
                    if ($cursor !== null) {
                        $queries[] = Query::cursorAfter($cursor);
                    }
                    $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', $queries));

                    foreach ($repositories as $repository) {
                        $authorization->skip(fn () => $dbForPlatform->deleteDocument('repositories', $repository->getId()));
                    }

                    $cursor = count($repositories) === 1000 ? $repositories[array_key_last($repositories)] : null;
                } while ($cursor !== null);

                $authorization->skip(fn () => $dbForPlatform->deleteDocument('installations', $installation->getId()));
            }

            $installationCursor = count($installations) === 1000 ? $installations[array_key_last($installations)] : null;
        } while ($installationCursor !== null);
    }

    private function handlePushEvent(
        Provider $provider,
        Git $adapter,
        array $parsedPayload,
        Resolver $vcs,
        Database $dbForPlatform,
        Authorization $authorization,
        BuildPublisher $publisherForBuilds,
        callable $getProjectDB,
        array $platform,
    ) {
        $key = $provider->getKey();

        $providerBranchDeleted = $parsedPayload["branchDeleted"] ?? false;
        $providerBranch = $parsedPayload["branch"] ?? '';
        $providerBranchUrl = $parsedPayload["branchUrl"] ?? '';
        $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
        $providerRepositoryName = $parsedPayload["repositoryName"] ?? '';
        $providerInstallationId = $parsedPayload["installationId"] ?? '';
        $providerRepositoryUrl = $parsedPayload["repositoryUrl"] ?? '';
        $providerCommitHash = $parsedPayload["commitHash"] ?? '';
        $providerRepositoryOwner = $parsedPayload["owner"] ?? '';
        $providerCommitAuthorName = $parsedPayload["headCommitAuthorName"] ?? '';
        $providerCommitAuthorEmail = $parsedPayload["headCommitAuthorEmail"] ?? '';
        $providerCommitAuthorUrl = $parsedPayload["authorUrl"] ?? '';
        $providerCommitMessage = $parsedPayload["headCommitMessage"] ?? '';
        $providerCommitUrl = $parsedPayload["headCommitUrl"] ?? '';

        Span::add("vcs.{$key}.event.repo.id", $providerRepositoryId);
        Span::add("vcs.{$key}.event.repo.name", $providerRepositoryName);
        Span::add("vcs.{$key}.event.branch", $providerBranch);
        Span::add("vcs.{$key}.event.installation.id", $providerInstallationId);

        $adapter = $this->authenticateAdapter($provider, $adapter, $vcs, $parsedPayload, $dbForPlatform, $authorization);
        if ($adapter === null) {
            return;
        }

        // Find associated repositories
        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::limit(100),
        ]));

        // Create new deployment only on push (not committed by us) and not when branch is deleted
        if ($providerCommitAuthorEmail !== APP_VCS_COMMIT_EMAIL && !$providerBranchDeleted) {
            $providerAffectedFiles = $parsedPayload['affectedFiles'] ?? [];
            $this->createGitDeployments($adapter, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', $providerAffectedFiles, false, $dbForPlatform, $authorization, $publisherForBuilds, $getProjectDB, $platform);
        }
    }

    private function handlePullRequestEvent(
        Provider $provider,
        Git $adapter,
        array $parsedPayload,
        Resolver $vcs,
        Database $dbForPlatform,
        Authorization $authorization,
        BuildPublisher $publisherForBuilds,
        callable $getProjectDB,
        array $platform,
    ) {
        $key = $provider->getKey();
        $action = $parsedPayload["action"] ?? '';

        // Gitea-compatible providers send 'synchronized', GitHub sends 'synchronize'
        if ($action == "opened" || $action == "reopened" || $action == "synchronize" || $action == "synchronized") {
            $providerBranch = $parsedPayload["branch"] ?? '';
            $providerBranchUrl = $parsedPayload["branchUrl"] ?? '';
            $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
            $providerRepositoryName = $parsedPayload["repositoryName"] ?? '';
            $providerInstallationId = $parsedPayload["installationId"] ?? '';
            $providerRepositoryUrl = $parsedPayload["repositoryUrl"] ?? '';
            $providerPullRequestId = $parsedPayload["pullRequestNumber"] ?? '';
            $providerCommitHash = $parsedPayload["commitHash"] ?? '';
            $providerRepositoryOwner = $parsedPayload["owner"] ?? '';
            $external = $parsedPayload["external"] ?? true;
            $providerCommitUrl = $parsedPayload["headCommitUrl"] ?? '';
            $providerCommitAuthorUrl = $parsedPayload["authorUrl"] ?? '';

            Span::add("vcs.{$key}.event.repo.id", $providerRepositoryId);
            Span::add("vcs.{$key}.event.repo.name", $providerRepositoryName);
            Span::add("vcs.{$key}.event.branch", $providerBranch);
            Span::add("vcs.{$key}.event.installation.id", $providerInstallationId);

            // Ignore sync for non-external. We handle it in push webhook
            if (!$external && ($action == "synchronize" || $action == "synchronized")) {
                return;
            }

            $adapter = $this->authenticateAdapter($provider, $adapter, $vcs, $parsedPayload, $dbForPlatform, $authorization);
            if ($adapter === null) {
                return;
            }

            try {
                $commitDetails = $adapter->getCommit($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash);
            } catch (\Throwable $e) {
                Console::warning("Failed to fetch commit '{$providerCommitHash}': " . $e->getMessage());
                $commitDetails = [];
            }
            $providerCommitAuthor = $commitDetails["commitAuthor"] ?? '';
            $providerCommitMessage = $commitDetails["commitMessage"] ?? '';

            $prFiles = $adapter->getPullRequestFiles($providerRepositoryOwner, $providerRepositoryName, $providerPullRequestId);
            $providerAffectedFiles = [
                ...array_column($prFiles, 'filename'),
                // Only renamed files include previous_filename; skip missing values from other file changes.
                ...array_filter(array_column($prFiles, 'previous_filename'))
            ];

            $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                Query::equal('providerRepositoryId', [$providerRepositoryId]),
                Query::orderDesc('$createdAt')
            ]));

            $this->createGitDeployments($adapter, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $providerAffectedFiles, $external, $dbForPlatform, $authorization, $publisherForBuilds, $getProjectDB, $platform);
        } elseif ($action == "closed") {
            // Allowed external contributions cleanup

            $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
            $providerPullRequestId = $parsedPayload["pullRequestNumber"] ?? '';
            $external = $parsedPayload["external"] ?? true;

            if ($external) {
                $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::orderDesc('$createdAt')
                ]));

                foreach ($repositories as $repository) {
                    $providerPullRequestIds = $repository->getAttribute('providerPullRequestIds', []);

                    if (\in_array($providerPullRequestId, $providerPullRequestIds)) {
                        $providerPullRequestIds = \array_diff($providerPullRequestIds, [$providerPullRequestId]);
                        $repository = $repository->setAttribute('providerPullRequestIds', $providerPullRequestIds);
                        $repository = $authorization->skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), new Document(['providerPullRequestIds' => $providerPullRequestIds])));
                    }
                }
            }
        }
    }
}
