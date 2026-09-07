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
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\VCS\Adapter\Git;

/**
 * Webhook receiver for a VCS provider. Verifies the delivery signature, then
 * turns every push and pull request it describes into deployments for the
 * repositories connected to it. Each repository deploys through the
 * installation it was connected with, its token refreshed first; app-based
 * providers (GitHub) act for the installation named in the delivery instead.
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
     * Author email Appwrite signs its own commits with on this provider.
     */
    abstract protected function getCommitEmail(): string;

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
        $signature = $request->getHeaderLine($vcs->getSignatureHeaderName(), '');
        $secretKey = $vcsWebhookSecret($key);

        $valid = empty($secretKey) ? !$this->requiresWebhookSecret() : $vcs->validateWebhookEvent($payload, $signature, $secretKey);
        Span::add("vcs.{$key}.event.signature.valid", $valid);

        if (!$valid) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Invalid webhook payload signature. Please make sure the webhook secret has same value in your ' . static::getProviderName() . ' webhook settings and in the _APP_VCS_' . \strtoupper($key) . '_WEBHOOK_SECRET environment variable');
        }

        // One delivery can describe several events, e.g. a push to many branches.
        $parsedPayloads = $vcs->getEvents($event, $payload);

        foreach ($parsedPayloads as $parsedPayload) {
            $this->handleEvent($event, $parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory);
        }

        $response->json(['events' => $parsedPayloads]);
    }

    /**
     * Runs before the delivery is verified, so a multi-region install can
     * forward it on unchanged.
     */
    protected function preprocessEvent(Request $request)
    {
        return;
    }

    /**
     * Whether a delivery is refused when no webhook secret is configured.
     */
    protected function requiresWebhookSecret(): bool
    {
        return true;
    }

    protected function handleEvent(
        string $event,
        array $parsedPayload,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ): void {
        match (true) {
            \in_array($event, $this->getPushEvents(), true) => $this->handlePushEvent($parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory),
            \in_array($event, $this->getPullRequestEvents(), true) => $this->handlePullRequestEvent($parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory),
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

        // Deploy only pushes we did not commit ourselves, and never a deleted branch.
        if ($providerCommitAuthorEmail === $this->getCommitEmail() || $providerBranchDeleted) {
            return;
        }

        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::limit(100),
        ]));

        $errors = [];
        foreach ($this->resolveAdapters($repositories, $parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $errors) as [$vcs, $providerInstallationId, $repositories]) {
            $this->createGitDeployments($vcs, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', $providerAffectedFiles, false, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory);
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
            // Allowed external contributions cleanup
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
            $providerAffectedFiles = [
                ...array_column($prFiles, 'filename'),
                // Only renamed files include previous_filename; skip missing values from other file changes.
                ...array_filter(array_column($prFiles, 'previous_filename')),
            ];

            $this->createGitDeployments($vcs, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $providerAffectedFiles, $external, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory);
        }

        if (!empty($errors)) {
            throw new Exception(Exception::GENERAL_UNKNOWN, \implode("\n", $errors));
        }
    }
}
