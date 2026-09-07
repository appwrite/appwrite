<?php

namespace Appwrite\Platform\Modules\VCS\Http\Events;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Appwrite\Vcs\RepositoryPullRequestCleanup;
use Utopia\Bus\Bus;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

/**
 * Webhook receiver for a VCS provider. Verifies the delivery signature, then
 * turns every push and pull request it describes into deployments for the
 * repositories connected to it. Each repository deploys through the
 * installation it was connected with, its token refreshed first. Providers
 * installed as an app rather than through OAuth act for the installation the
 * delivery names, and also report when that installation is removed.
 */
abstract class Base extends Action
{
    use HTTP;
    use Deployment;

    /**
     * Provider key in the `vcs` config registry.
     */
    abstract public static function getProvider(): string;

    /**
     * Provider display name, used in error messages.
     */
    abstract public static function getProviderName(): string;

    /**
     * Author emails our own commits carry on this provider.
     *
     * @return string[]
     */
    abstract protected function getCommitEmails(): array;

    /**
     * Event names the provider sends for a push.
     *
     * @return string[]
     */
    abstract protected function getPushEvents(): array;

    /**
     * Event names the provider sends for a pull request.
     *
     * @return string[]
     */
    abstract protected function getPullRequestEvents(): array;

    /**
     * Event names reporting a change to the installation itself. Only app-based
     * providers send these; OAuth installations are removed from the console.
     *
     * @return string[]
     */
    protected function getInstallationEvents(): array
    {
        return [];
    }

    public function __construct()
    {
        $key = static::getProvider();

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/' . $key . '/events')
            ->desc('Create event')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->inject('vcsFactory')
            ->inject('vcsWebhookSecret')
            ->inject('installationTokens')
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('bus')
            ->inject('getProjectDB')
            ->inject('deploymentsFactory')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        VcsFactory $vcsFactory,
        callable $vcsWebhookSecret,
        InstallationTokens $installationTokens,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        callable $deploymentsFactory,
        array $platform
    ) {
        $this->preprocessEvent($request);

        $key = static::getProvider();
        $vcs = $vcsFactory->fromProvider($key);

        $event = $request->getHeaderLine($vcs->getEventHeaderName(), '');
        Span::add("vcs.{$key}.event.name", $event);

        $payload = $request->getRawPayload();
        $this->verifyDelivery($request, $vcs, $payload, $vcsWebhookSecret);

        if (!$this->claimDelivery($request, $response, $dbForPlatform, $authorization)) {
            return;
        }

        // One delivery can describe several events, e.g. a push to many branches.
        $parsedPayloads = $vcs->getEvents($event, $payload);

        foreach ($parsedPayloads as $parsedPayload) {
            $this->handleEvent($event, $parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);
        }

        $response->json(['events' => $parsedPayloads]);
    }

    /**
     * Runs before the delivery is verified. Appwrite Cloud overrides this to
     * fan the raw delivery out to its other regions. Deliberately untyped:
     * that override declares no return type, and adding one here would make
     * it incompatible.
     */
    protected function preprocessEvent(Request $request)
    {
        return;
    }

    protected function requiresWebhookSecret(): bool
    {
        return true;
    }

    /**
     * Refuses a delivery the provider did not sign. Providers that authenticate
     * with something other than a shared secret replace this wholesale.
     */
    protected function verifyDelivery(Request $request, Git $vcs, string $payload, callable $vcsWebhookSecret): void
    {
        $key = static::getProvider();
        $signature = $request->getHeaderLine($vcs->getSignatureHeaderName(), '');
        $secretKey = $vcsWebhookSecret($key);

        $valid = empty($secretKey) ? !$this->requiresWebhookSecret() : $vcs->validateWebhookEvent($payload, $signature, $secretKey);
        Span::add("vcs.{$key}.event.signature.valid", $valid);

        if (!$valid) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Invalid webhook payload signature. Please make sure the webhook secret has same value in your ' . static::getProviderName() . ' webhook settings and in the _APP_VCS_' . \strtoupper($key) . '_WEBHOOK_SECRET environment variable');
        }
    }

    /**
     * Answers whether this delivery should be processed. A provider that
     * retries anything it has not seen acknowledged claims the delivery here
     * and answers false for one already in flight, having sent the response.
     */
    protected function claimDelivery(Request $request, Response $response, Database $dbForPlatform, Authorization $authorization): bool
    {
        return true;
    }

    /**
     * Whether an event name the provider sent is one of the given names.
     * Providers that namespace an event by its action match on the prefix.
     *
     * @param string[] $names
     */
    protected function matchesEvent(string $event, array $names): bool
    {
        return \in_array($event, $names, true);
    }

    protected function handleEvent(
        string $event,
        array $parsedPayload,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ): void {
        match (true) {
            $this->matchesEvent($event, $this->getPushEvents()) => $this->handlePushEvent($parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory),
            $this->matchesEvent($event, $this->getPullRequestEvents()) => $this->handlePullRequestEvent($parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory),
            $this->matchesEvent($event, $this->getInstallationEvents()) => $this->handleInstallationEvent($parsedPayload, $dbForPlatform, $authorization, $getProjectDB),
            default => null,
        };
    }

    /**
     * Pairs the connected repositories with an adapter acting for the
     * installation they were connected through, refreshing each stored token
     * first. A refresh or adapter failure is pushed onto $errors instead of
     * swallowed, so the caller can surface a non-2xx response and the
     * provider logs a failed delivery.
     *
     * @param Document[] $repositories
     * @return array<array{Git, string, Document[]}> adapter, installation id for its owner lookup, repositories
     */
    protected function resolveAdapters(
        array $repositories,
        array $parsedPayload,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Database $dbForPlatform,
        Authorization $authorization,
        array &$errors,
    ): array {
        $key = static::getProvider();
        $adapters = [];

        foreach ($repositories as $repository) {
            $installationId = $repository->getAttribute('installationId', '');
            $installation = $authorization->skip(fn () => $dbForPlatform->getDocument('installations', $installationId));

            // Repository ids are scoped to a provider's server, so the same id can belong to another provider's repository.
            if ($installation->isEmpty() || $installation->getAttribute('provider', 'github') !== $key) {
                continue;
            }

            try {
                $installation = $installationTokens->refreshForInstallation($installation, $dbForPlatform, $vcsFactory);
                $adapters[] = [$vcsFactory->fromInstallation($installation), $installationId, [$repository]];
            } catch (\Throwable $error) {
                $message = 'Failed to resolve ' . static::getProviderName() . " adapter for installation '{$installation->getId()}': " . $error->getMessage();
                Console::warning($message);
                Span::add("vcs.{$key}.event.installation.{$installation->getId()}.error", $message);
                $errors[] = $message;
            }
        }

        return $adapters;
    }

    protected function handlePushEvent(
        array $parsedPayload,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ): void {
        $key = static::getProvider();
        $providerBranchDeleted = $parsedPayload['branchDeleted'] ?? false;
        $providerBranch = $parsedPayload['branch'] ?? '';
        $providerBranchUrl = $parsedPayload['branchUrl'] ?? '';
        $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
        $providerRepositoryName = $parsedPayload['repositoryName'] ?? '';
        $providerRepositoryUrl = $parsedPayload['repositoryUrl'] ?? '';
        $providerCommitHash = $parsedPayload['commitHash'] ?? '';
        $providerRepositoryOwner = $parsedPayload['owner'] ?? '';
        $providerCommitAuthorName = $parsedPayload['headCommitAuthorName'] ?? '';
        $providerCommitAuthorEmail = $parsedPayload['headCommitAuthorEmail'] ?? '';
        $providerCommitAuthorUrl = $parsedPayload['authorUrl'] ?? '';
        $providerCommitMessage = $parsedPayload['headCommitMessage'] ?? '';
        $providerCommitUrl = $parsedPayload['headCommitUrl'] ?? '';
        $providerAffectedFiles = $parsedPayload['affectedFiles'] ?? [];

        Span::add("vcs.{$key}.event.repo.id", $providerRepositoryId);
        Span::add("vcs.{$key}.event.repo.name", $providerRepositoryName);
        Span::add("vcs.{$key}.event.branch", $providerBranch);

        // Our own commits would otherwise deploy themselves in a loop.
        if (\in_array($providerCommitAuthorEmail, $this->getCommitEmails(), true) || $providerBranchDeleted) {
            return;
        }

        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::limit(100),
        ]));

        $errors = [];
        foreach ($this->resolveAdapters($repositories, $parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $errors) as [$vcs, $providerInstallationId, $repositories]) {
            $this->createGitDeployments($vcs, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', $providerAffectedFiles, false, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);
        }

        if (!empty($errors)) {
            throw new Exception(Exception::GENERAL_UNKNOWN, \implode("\n", $errors));
        }
    }

    protected function handlePullRequestEvent(
        array $parsedPayload,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ): void {
        $key = static::getProvider();
        $action = $parsedPayload['action'] ?? '';
        $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
        $providerPullRequestId = $parsedPayload['pullRequestNumber'] ?? '';
        $external = $parsedPayload['external'] ?? true;

        if ($action === 'closed') {
            // Only external pull requests were ever recorded as authorized.
            if ($external) {
                (new RepositoryPullRequestCleanup())->remove($dbForPlatform, $authorization, $key, $providerRepositoryId, $providerPullRequestId);
            }

            return;
        }

        if (!\in_array($action, ['opened', 'reopened', 'synchronize'], true)) {
            return;
        }

        $providerBranch = $parsedPayload['branch'] ?? '';
        $providerBranchUrl = $parsedPayload['branchUrl'] ?? '';
        $providerRepositoryName = $parsedPayload['repositoryName'] ?? '';
        $providerRepositoryUrl = $parsedPayload['repositoryUrl'] ?? '';
        $providerCommitHash = $parsedPayload['commitHash'] ?? '';
        $providerRepositoryOwner = $parsedPayload['owner'] ?? '';
        $providerCommitUrl = $parsedPayload['headCommitUrl'] ?? '';
        $providerCommitAuthorUrl = $parsedPayload['authorUrl'] ?? '';

        Span::add("vcs.{$key}.event.repo.id", $providerRepositoryId);
        Span::add("vcs.{$key}.event.repo.name", $providerRepositoryName);
        Span::add("vcs.{$key}.event.branch", $providerBranch);

        // Ignore sync for non-external. We handle it in the push webhook.
        if (!$external && $action === 'synchronize') {
            return;
        }

        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::orderDesc('$createdAt'),
            Query::limit(100),
        ]));

        $errors = [];
        foreach ($this->resolveAdapters($repositories, $parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $errors) as [$vcs, $providerInstallationId, $repositories]) {
            try {
                $commitDetails = $vcs->getCommit($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash);
            } catch (\Throwable $e) {
                Console::warning("Failed to fetch commit '{$providerCommitHash}': " . $e->getMessage());
                $commitDetails = [];
            }
            $providerCommitAuthor = $commitDetails['commitAuthor'] ?? '';
            $providerCommitMessage = $commitDetails['commitMessage'] ?? '';

            $prFiles = $vcs->getPullRequestFiles($providerRepositoryOwner, $providerRepositoryName, $providerPullRequestId);
            $providerAffectedFiles = $this->getAffectedFiles($prFiles);

            $this->createGitDeployments($vcs, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $providerAffectedFiles, $external, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);
        }

        if (!empty($errors)) {
            throw new Exception(Exception::GENERAL_UNKNOWN, \implode("\n", $errors));
        }
    }

    /**
     * Paths a pull request touches, including where a renamed file came from so
     * a path trigger still matches its old location. Providers whose file rows
     * name that key differently override this.
     *
     * @param array<array<string, mixed>> $prFiles
     * @return string[]
     */
    protected function getAffectedFiles(array $prFiles): array
    {
        return [
            ...array_column($prFiles, 'filename'),
            // Only renamed files carry a previous path; drop the gaps other changes leave.
            ...array_filter(array_column($prFiles, 'previous_filename')),
        ];
    }

    /**
     * One adapter acting for the installation the delivery names. Providers
     * installed as an app store no token, so there is nothing to look up or
     * refresh per repository.
     *
     * @param Document[] $repositories
     * @return array<array{Git, string, Document[]}>
     */
    protected function resolveAdaptersFromDelivery(array $repositories, array $parsedPayload, VcsFactory $vcsFactory): array
    {
        $key = static::getProvider();
        $providerInstallationId = $parsedPayload['installationId'] ?? '';
        Span::add("vcs.{$key}.event.installation.id", $providerInstallationId);

        $vcs = $vcsFactory->fromInstallation(new Document([
            'provider' => $key,
            'providerInstallationId' => $providerInstallationId,
        ]));

        return [[$vcs, $providerInstallationId, $repositories]];
    }

    /**
     * Detaches everything an installation held once it is removed at the
     * provider: every function and site it fed loses its repository, and the
     * repository and installation records go with it.
     */
    protected function handleInstallationEvent(
        array $parsedPayload,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
    ): void {
        if (($parsedPayload['action'] ?? '') !== 'deleted') {
            return;
        }

        $providerInstallationId = $parsedPayload['installationId'] ?? '';

        $installationCursor = null;
        do {
            $installationQueries = [
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::equal('provider', [static::getProvider()]),
                Query::limit(1000),
            ];
            if ($installationCursor !== null) {
                $installationQueries[] = Query::cursorAfter($installationCursor);
            }
            $installations = $authorization->skip(fn () => $dbForPlatform->find('installations', $installationQueries));

            foreach ($installations as $installation) {
                $projectId = $installation->getAttribute('projectId', '');
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

                if (!$project->isEmpty() && $this->isProjectInCurrentRegion($project)) {
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

    private function isProjectInCurrentRegion(Document $project): bool
    {
        try {
            $dsn = new DSN($project->getAttribute('database'));
            $databaseName = $dsn->getHost();
        } catch (\InvalidArgumentException) {
            $databaseName = $project->getAttribute('database');
        }

        $databases = Config::getParam('pools-database', []);
        if (!\in_array($databaseName, $databases)) {
            Console::warning("Skipping project {$project->getId()}: database '{$databaseName}' is not part of region " . System::getEnv('_APP_REGION'));
            return false;
        }

        return true;
    }
}
