<?php

namespace Appwrite\Platform\Modules\VCS\Http\Entire\Events;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Bus\Bus;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\Entire;

class Create extends Action
{
    use HTTP;
    use Deployment;

    /**
     * Active signing keys per Entire cell, cached for the worker's lifetime.
     * Each cell signs its own deliveries; the sets rotate rarely and a
     * delivery that fails on a stale set refetches once.
     *
     * @var array<string, array<string>>
     */
    protected static array $signingKeys = [];

    public static function getName()
    {
        return 'createVCSEntireEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/entire/events')
            ->desc('Create event')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->inject('vcsFactory')
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
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        callable $deploymentsFactory,
        array $platform
    ) {
        /** @var Entire $vcs */
        $vcs = $vcsFactory->fromProvider('entire');

        $payload = $request->getRawPayload();
        $token = $request->getHeaderLine($vcs->getSignatureHeaderName(), '');
        $plugin = System::getEnv('_APP_VCS_ENTIRE_PLUGIN', '');

        // Entire signs each delivery with a short-lived EdDSA JWT carried as a
        // bearer, bound to the body by its digest claim. Its issuer is the
        // cell that sent it, verified against that cell's published JWKS, and
        // its audience is this plugin. Only an issuer of the configured Entire
        // deployment is consulted, so no keys are ever fetched from a host a
        // forged token names.
        $valid = false;
        $issuer = '';
        try {
            $claims = $vcs->getWebhookClaims($token);
            $issuer = \strval($claims['iss'] ?? '');

            $audience = \is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? ''];

            if (empty($plugin) || !\in_array($plugin, $audience, true)) {
                Console::warning('Entire delivery refused: audience ' . \json_encode($audience) . ' is not the plugin ' . $plugin);
            } elseif (!$vcs->isTrustedIssuer($issuer)) {
                Console::warning('Entire delivery refused: issuer ' . $issuer . ' is not a cell of the configured Entire deployment');
            } else {
                foreach ($this->signingKeys($vcs, $issuer) as $publicKey) {
                    if ($vcs->validateWebhookEvent($payload, $token, $publicKey)) {
                        $valid = true;
                        break;
                    }
                }

                // The key set may have rotated since it was cached.
                if (!$valid) {
                    foreach ($this->signingKeys($vcs, $issuer, refresh: true) as $publicKey) {
                        if ($vcs->validateWebhookEvent($payload, $token, $publicKey)) {
                            $valid = true;
                            break;
                        }
                    }
                }

                if (!$valid) {
                    Console::warning('Entire delivery refused: the token did not verify against the keys of ' . $issuer . ' (kid ' . \strval($claims['kid'] ?? '') . ')');
                }
            }
        } catch (\Throwable $e) {
            Console::warning('Failed to read Entire delivery token: ' . $e->getMessage());
        }

        Span::add('vcs.entire.event.issuer', $issuer);
        Span::add('vcs.entire.event.signature.valid', $valid);

        if (!$valid) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Invalid webhook delivery token. The delivery could not be verified against Entire\'s published signing keys.');
        }

        $this->preprocessEvent($request);

        $event = $vcs->getEventType($payload);
        Span::add('vcs.entire.event.name', $event);

        $parsedPayloads = $vcs->getEvents($event, $payload);

        // Entire delivers at least once and retries anything not acknowledged
        // with a 2xx in time. Processing here is synchronous and can outlast
        // that window, so a push claims the delivery for each repository
        // before deploying it - a retry of a delivery already being processed
        // must not deploy again.
        // TODO: acknowledge first and process asynchronously instead.
        $failure = null;
        try {
            foreach ($parsedPayloads as $parsedPayload) {
                match (true) {
                    \str_starts_with($event, 'ref.') => $this->handlePushEvent($parsedPayload, $vcsFactory, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory),
                    \str_starts_with($event, Entire::EVENT_INSTALLATION . '.') => $this->handleInstallationEvent($parsedPayload, $dbForPlatform, $authorization, $getProjectDB),
                    default => null,
                };
            }
        } catch (\Throwable $error) {
            $failure = $error;
        }

        // Runs whatever happened above, so work elsewhere never waits on this
        // one; its failure fails the delivery as well, and this one's own
        // failure is the one reported.
        try {
            $this->postprocessEvent($request);
        } catch (\Throwable $error) {
            $failure ??= $error;
        }

        if ($failure !== null) {
            throw $failure;
        }

        $response->json(['events' => $parsedPayloads]);
    }

    /**
     * Runs once a delivery has verified, before it is handled, like GitHub's.
     */
    protected function preprocessEvent(Request $request)
    {
        return;
    }

    /**
     * Runs once this region has handled a delivery, before it is acknowledged.
     * Throwing fails the delivery, so Entire delivers it again, and each
     * repository that already deployed it has it claimed and skips it. Cloud
     * overrides it to forward the delivery to its other regions and fail the
     * delivery when one of them did not take it.
     */
    protected function postprocessEvent(Request $request): void
    {
    }

    /**
     * A cell's active signing keys, from the adapter, cached for the worker's
     * lifetime - the adapter memoizes only per instance, and a new adapter is
     * built for every delivery.
     *
     * @return array<string>
     */
    protected function signingKeys(Entire $vcs, string $issuer, bool $refresh = false): array
    {
        if (!$refresh && isset(self::$signingKeys[$issuer])) {
            return self::$signingKeys[$issuer];
        }

        $keys = [];

        try {
            $keys = $vcs->getSigningKeys($issuer, $refresh);
        } catch (\Throwable $e) {
            Console::warning('Failed to fetch Entire signing keys from ' . $issuer . ': ' . $e->getMessage());
        }

        // Never cache an empty set - a fetch hiccup would reject deliveries
        // until the worker restarts.
        if (!empty($keys)) {
            self::$signingKeys[$issuer] = $keys;
        }

        return self::$signingKeys[$issuer] ?? [];
    }

    protected function handleInstallationEvent(
        array $parsedPayload,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
    ) {
        if ($parsedPayload['action'] !== 'deleted') {
            return;
        }

        $providerInstallationId = $parsedPayload['installationId'];

        $installationCursor = null;
        do {
            $installationQueries = [
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::equal('provider', ['entire']),
                Query::limit(1000),
            ];
            if ($installationCursor !== null) {
                $installationQueries[] = Query::cursorAfter($installationCursor);
            }
            $installations = $authorization->skip(fn () => $dbForPlatform->find('installations', $installationQueries));

            foreach ($installations as $installation) {
                $projectId = $installation->getAttribute('projectId', '');
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

                // Every region gets the delivery, and the installation and repository
                // rows are shared, so each region removes only its own projects'
                // ones - or a region that runs later would find nothing to unlink
                // its resources from. A deleted project's rows go wherever this runs.
                if (!$project->isEmpty() && !$this->isProjectInCurrentRegion($project)) {
                    continue;
                }

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

    /**
     * Whether the repository's resource got a deployment of this commit since
     * the attempt began that did not fail - a push can fail after its
     * deployment is accepted, and retrying it then would build the commit
     * twice. One that failed, such as a build that could not be queued, is
     * retried. A lookup that fails counts as no deployment, so the push is
     * retried rather than dropped.
     */
    private function hasDeployed(Document $repository, string $commitHash, string $branch, string $since, Database $dbForPlatform, Authorization $authorization, callable $getProjectDB): bool
    {
        try {
            $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $repository->getAttribute('projectId', '')));
            if ($project->isEmpty() || !$this->isProjectInCurrentRegion($project)) {
                return false;
            }

            $dbForProject = $getProjectDB($project);

            return !$authorization->skip(fn () => $dbForProject->findOne('deployments', [
                Query::equal('resourceId', [$repository->getAttribute('resourceId', '')]),
                Query::equal('resourceType', [$repository->getAttribute('resourceType') === 'function' ? 'functions' : 'sites']),
                Query::equal('providerCommitHash', [$commitHash]),
                Query::equal('providerBranch', [$branch]),
                Query::greaterThanEqual('$createdAt', $since),
                Query::notEqual('status', 'failed'),
            ]))->isEmpty();
        } catch (\Throwable $error) {
            Console::warning("Failed to look up deployments of repository '{$repository->getId()}': " . $error->getMessage());

            return false;
        }
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
        Bus $bus,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ) {
        $providerBranchDeleted = $parsedPayload['branchDeleted'] ?? false;
        $providerBranchCreated = $parsedPayload['branchCreated'] ?? false;
        $providerBranch = $parsedPayload['branch'] ?? '';
        $providerBranchUrl = $parsedPayload['branchUrl'] ?? '';
        $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
        $providerRepositoryName = $parsedPayload['repositoryName'] ?? '';
        $providerInstallationId = $parsedPayload['installationId'] ?? '';
        $providerRepositoryUrl = $parsedPayload['repositoryUrl'] ?? '';
        $providerCommitHash = $parsedPayload['commitHash'] ?? '';
        $providerPreviousCommitHash = $parsedPayload['previousCommitHash'] ?? '';
        $providerRepositoryOwner = $parsedPayload['owner'] ?? '';
        $providerCommitAuthorName = $parsedPayload['headCommitAuthorName'] ?? '';
        $providerCommitUrl = $parsedPayload['headCommitUrl'] ?? '';

        Span::add('vcs.entire.event.repo.id', $providerRepositoryId);
        Span::add('vcs.entire.event.repo.name', $providerRepositoryName);
        Span::add('vcs.entire.event.branch', $providerBranch);
        Span::add('vcs.entire.event.installation.id', $providerInstallationId);

        // Nothing deploys from a deleted branch
        if ($providerBranchDeleted) {
            return;
        }

        // Find associated repositories
        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::limit(100),
        ]));

        // A delivery for a repository nothing deploys from needs no token
        if (empty($repositories)) {
            return;
        }

        /** @var Entire $vcs */
        $vcs = $vcsFactory->fromInstallation(new Document([
            'provider' => 'entire',
            'providerInstallationId' => $providerInstallationId,
        ]));

        // The delivery names the cell serving the repository
        $vcs->setRepositoryEndpoint($providerRepositoryId, $parsedPayload['apiUrl'] ?? '');

        // A delivery names who pushed, not who authored the commit, and
        // carries no message or file list; the commit itself does
        $providerCommitAuthorEmail = '';
        $providerCommitMessage = '';
        try {
            $commit = $vcs->getCommit($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash);
            $providerCommitAuthorName = $commit['commitAuthor'] ?? $providerCommitAuthorName;
            $providerCommitAuthorEmail = $commit['commitAuthorEmail'] ?? '';
            $providerCommitMessage = $commit['commitMessage'] ?? '';
        } catch (\Throwable $e) {
            Console::warning("Failed to fetch commit '{$providerCommitHash}': " . $e->getMessage());
        }

        // Create new deployment only on push not committed by us
        if (\in_array($providerCommitAuthorEmail, [APP_VCS_GITHUB_EMAIL, APP_VCS_ENTIRE_EMAIL], true)) {
            return;
        }

        $providerAffectedFiles = [];
        // A new branch has no previous tip to compare against
        if (!$providerBranchCreated && !empty($providerPreviousCommitHash) && \trim($providerPreviousCommitHash, '0') !== '') {
            try {
                $files = $vcs->compareFiles($providerRepositoryOwner, $providerRepositoryName, $providerPreviousCommitHash, $providerCommitHash);
                $providerAffectedFiles = [
                    ...\array_column($files, 'filename'),
                    // Only renamed files carry a previous filename; skip missing values from other file changes.
                    ...\array_filter(\array_column($files, 'previous_filename')),
                ];
            } catch (\Throwable $e) {
                Console::warning("Failed to compare '{$providerPreviousCommitHash}' with '{$providerCommitHash}': " . $e->getMessage());
            }
        }

        // Entire delivers no trail events, so a push onto a branch that has
        // an open trail reports on it from here. Trails never come from a
        // fork, so none is ever external.
        $providerPullRequestId = '';
        try {
            $trail = $vcs->getPullRequestFromBranch($providerRepositoryOwner, $providerRepositoryName, $providerBranch);
            $providerPullRequestId = !empty($trail['number']) ? \strval($trail['number']) : '';
        } catch (\Throwable $e) {
            Console::warning("Failed to look up the trail of branch '{$providerBranch}': " . $e->getMessage());
        }

        // Entire retries a delivery it got an error for. Each repository claims
        // the delivery once and gives the claim back only when it failed without
        // deploying, so a retry deploys just the repositories that did not, and
        // one that keeps failing never redeploys the others.
        $deliveryId = \strval($parsedPayload['deliveryId'] ?? '');
        $errors = [];
        foreach ($repositories as $repository) {
            // Every region gets the delivery and they share the platform
            // database, so a region claims only the repositories it deploys -
            // a claim on another region's would make that region skip it
            $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $repository->getAttribute('projectId', '')));
            if ($project->isEmpty() || !$this->isProjectInCurrentRegion($project)) {
                continue;
            }

            // Document ids are capped at 36 characters; a digest prefix keeps the claim within it
            $claim = 'entire-' . \substr(\hash('sha256', $deliveryId . ':' . $repository->getId()), 0, 29);
            if ($deliveryId !== '') {
                try {
                    $authorization->skip(fn () => $dbForPlatform->createDocument('vcsCommentLocks', new Document(['$id' => $claim])));
                } catch (Duplicate) {
                    continue;
                }
            }

            $attemptedAt = DateTime::now();
            try {
                $this->createGitDeployments($vcs, $providerInstallationId, [$repository], $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, '', $providerCommitMessage, $providerCommitUrl, '', $providerAffectedFiles, false, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);
            } catch (\Throwable $error) {
                if ($deliveryId !== '' && !$this->hasDeployed($repository, $providerCommitHash, $providerBranch, $attemptedAt, $dbForPlatform, $authorization, $getProjectDB)) {
                    $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $claim));
                }
                $errors[] = $error->getMessage();
                continue;
            }

            // With a pull request id the trait only comments and links the
            // deployment the push created, the way a pull request event would.
            // The deployment exists, so a failure here must not hand the
            // delivery back for a retry that would deploy it again.
            if ($providerPullRequestId !== '') {
                try {
                    $this->createGitDeployments($vcs, $providerInstallationId, [$repository], $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, '', $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $providerAffectedFiles, false, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);
                } catch (\Throwable $error) {
                    Console::warning("Failed to report on trail #{$providerPullRequestId}: " . $error->getMessage());
                }
            }
        }

        if (!empty($errors)) {
            throw new Exception(Exception::GENERAL_UNKNOWN, \implode("\n", $errors));
        }
    }
}
