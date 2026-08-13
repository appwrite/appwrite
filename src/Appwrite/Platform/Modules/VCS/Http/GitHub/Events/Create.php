<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Events;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\RepositoryPullRequestCleanup;
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
use Utopia\VCS\Adapter\Git\GitHub;

class Create extends Action
{
    use HTTP;
    use Deployment;

    public static function getName()
    {
        return 'createVCSGitHubEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/github/events')
            ->desc('Create event')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->inject('vcsWebhookSecret')
            ->inject('vcsFactory')
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
        callable $vcsWebhookSecret,
        VcsFactory $vcsFactory,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        callable $deploymentsFactory,
        array $platform
    ) {
        $this->preprocessEvent($request);

        $vcs = $vcsFactory->fromProvider('github');

        $event = $request->getHeaderLine('x-github-event', '');
        Span::add('vcs.github.event.name', $event);

        $payload = $request->getRawPayload();
        $signature = $request->getHeaderLine('x-hub-signature-256', '');
        $secretKey = $vcsWebhookSecret('github');

        $valid = empty($secretKey) || $vcs->validateWebhookEvent($payload, $signature, $secretKey);
        Span::add('vcs.github.event.signature.valid', $valid);

        if (!$valid) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, "Invalid webhook payload signature. Please make sure the webhook secret has same value in your GitHub app and in the _APP_VCS_GITHUB_WEBHOOK_SECRET environment variable");
        }

        $parsedPayloads = $vcs->getEvents($event, $payload);

        foreach ($parsedPayloads as $parsedPayload) {
            match ($event) {
                GitHub::EVENT_INSTALLATION => $this->handleInstallationEvent($parsedPayload, $dbForPlatform, $authorization, $getProjectDB),
                GitHub::EVENT_PUSH => $this->handlePushEvent($parsedPayload, $vcsFactory, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory),
                GitHub::EVENT_PULL_REQUEST => $this->handlePullRequestEvent($parsedPayload, $vcsFactory, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory),
                default => null,
            };
        }

        $response->json(['events' => $parsedPayloads]);
    }

    protected function preprocessEvent(Request $request)
    {
        return;
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

        $authorization->skip(fn () => $dbForPlatform->foreach('installations', function (Document $installation) use ($dbForPlatform, $getProjectDB) {
            $project = $dbForPlatform->getDocument('projects', $installation->getAttribute('projectId', ''));

            if (!$project->isEmpty() && $this->isProjectInCurrentRegion($project)) {
                $dbForProject = $getProjectDB($project);

                foreach (['functions', 'sites'] as $collection) {
                    $dbForProject->foreach($collection, function (Document $resource) use ($dbForProject, $collection) {
                        $dbForProject->updateDocument($collection, $resource->getId(), new Document([
                            'installationId' => '',
                            'installationInternalId' => '',
                            'providerRepositoryId' => '',
                            'providerBranch' => '',
                            'providerSilentMode' => false,
                            'providerRootDirectory' => '',
                            'repositoryId' => '',
                            'repositoryInternalId' => '',
                        ]));
                    }, [
                        Query::equal('installationInternalId', [$installation->getSequence()]),
                        Query::limit(1000),
                    ]);
                }
            }

            $dbForPlatform->foreach('repositories', function (Document $repository) use ($dbForPlatform) {
                $dbForPlatform->deleteDocument('repositories', $repository->getId());
            }, [
                Query::equal('installationInternalId', [$installation->getSequence()]),
                Query::limit(1000),
            ]);

            $dbForPlatform->deleteDocument('installations', $installation->getId());
        }, [
            Query::equal('providerInstallationId', [$providerInstallationId]),
            Query::equal('provider', ['github']),
            Query::limit(1000),
        ]));
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

    private function handlePushEvent(
        array $parsedPayload,
        VcsFactory $vcsFactory,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ) {
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

        Span::add('vcs.github.event.repo.id', $providerRepositoryId);
        Span::add('vcs.github.event.repo.name', $providerRepositoryName);
        Span::add('vcs.github.event.branch', $providerBranch);
        Span::add('vcs.github.event.installation.id', $providerInstallationId);

        // Create new deployment only on push (not committed by us) and not when branch is deleted
        if ($providerCommitAuthorEmail === APP_VCS_GITHUB_EMAIL || $providerBranchDeleted) {
            return;
        }

        $vcs = $vcsFactory->fromInstallation(new Document([
            'provider' => 'github',
            'providerInstallationId' => $providerInstallationId,
        ]));

        // Find associated repositories
        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::limit(100),
        ]));

        $providerAffectedFiles = $parsedPayload['affectedFiles'] ?? [];
        $this->createGitDeployments($vcs, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', $providerAffectedFiles, false, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory);
    }

    private function handlePullRequestEvent(
        array $parsedPayload,
        VcsFactory $vcsFactory,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ) {
        $action = $parsedPayload["action"] ?? '';

        if ($action === "closed") {
            // Allowed external contributions cleanup
            if ($parsedPayload["external"] ?? true) {
                (new RepositoryPullRequestCleanup())->remove($dbForPlatform, $authorization, 'github', $parsedPayload["repositoryId"] ?? '', $parsedPayload["pullRequestNumber"] ?? '');
            }

            return;
        }

        if (!\in_array($action, ["opened", "reopened", "synchronize"])) {
            return;
        }

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

        Span::add('vcs.github.event.repo.id', $providerRepositoryId);
        Span::add('vcs.github.event.repo.name', $providerRepositoryName);
        Span::add('vcs.github.event.branch', $providerBranch);
        Span::add('vcs.github.event.installation.id', $providerInstallationId);

        // Ignore sync for non-external. We handle it in push webhook
        if (!$external && $action === "synchronize") {
            return;
        }

        $vcs = $vcsFactory->fromInstallation(new Document([
            'provider' => 'github',
            'providerInstallationId' => $providerInstallationId,
        ]));

        try {
            $commitDetails = $vcs->getCommit($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash);
        } catch (\Throwable $e) {
            Console::warning("Failed to fetch commit '{$providerCommitHash}': " . $e->getMessage());
            $commitDetails = [];
        }
        $providerCommitAuthor = $commitDetails["commitAuthor"] ?? '';
        $providerCommitMessage = $commitDetails["commitMessage"] ?? '';

        $prFiles = $vcs->getPullRequestFiles($providerRepositoryOwner, $providerRepositoryName, $providerPullRequestId);
        $providerAffectedFiles = [
            ...array_column($prFiles, 'filename'),
            // Only renamed files include previous_filename; skip missing values from other file changes.
            ...array_filter(array_column($prFiles, 'previous_filename'))
        ];

        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::orderDesc('$createdAt')
        ]));

        $this->createGitDeployments($vcs, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $providerAffectedFiles, $external, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory);
    }
}
