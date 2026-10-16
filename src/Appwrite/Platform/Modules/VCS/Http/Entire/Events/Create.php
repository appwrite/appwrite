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

    /**
     * Whether the delivery being handled has started creating deployments.
     * A failure before that is safe to retry; one after it would deploy the
     * same commit again, so the delivery stays claimed.
     */
    private bool $deploying = false;

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

        $event = $vcs->getEventType($payload);
        Span::add('vcs.entire.event.name', $event);

        $parsedPayloads = $vcs->getEvents($event, $payload);

        // Entire delivers at least once and retries anything not acknowledged
        // with a 2xx in time. Processing here is synchronous and can outlast
        // that window, so claim the delivery id before doing the work - a
        // retry of a delivery already being processed must not deploy again.
        // TODO: acknowledge first and process asynchronously instead.
        $deliveryId = \strval($parsedPayloads[0]['deliveryId'] ?? '');
        // Document ids are capped at 36 characters; a digest prefix keeps the claim within it
        $claim = 'entire-' . \substr(\hash('sha256', $deliveryId), 0, 29);
        if (!empty($deliveryId)) {
            try {
                $authorization->skip(fn () => $dbForPlatform->createDocument('vcsCommentLocks', new Document(['$id' => $claim])));
            } catch (Duplicate) {
                $response->json(['events' => [], 'duplicate' => true]);
                return;
            }
        }

        $this->deploying = false;

        try {
            foreach ($parsedPayloads as $parsedPayload) {
                match (true) {
                    \str_starts_with($event, 'ref.') => $this->handlePushEvent($parsedPayload, $vcsFactory, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory),
                    \str_starts_with($event, Entire::EVENT_INSTALLATION . '.') => $this->handleInstallationEvent($parsedPayload, $dbForPlatform, $authorization, $getProjectDB),
                    default => null,
                };
            }
        } catch (\Throwable $error) {
            // A delivery that failed is answered with an error, which Entire
            // retries. One that failed before any deployment was created is
            // released so the retry is not taken for a duplicate; one that
            // failed after stays claimed, or the retry would deploy the
            // commit again.
            if (!empty($deliveryId) && !$this->isDeploying()) {
                $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $claim));
            }

            throw $error;
        }

        $response->json(['events' => $parsedPayloads]);
    }

    private function isDeploying(): bool
    {
        return $this->deploying;
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

        // The push creates the deployments; with a pull request id the trait
        // only comments and links them, the way a pull request event would,
        // so the trail gets a second pass once they exist
        $this->deploying = true;
        $this->createGitDeployments($vcs, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, '', $providerCommitMessage, $providerCommitUrl, '', $providerAffectedFiles, false, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);

        if ($providerPullRequestId !== '') {
            $this->createGitDeployments($vcs, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, '', $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $providerAffectedFiles, false, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);
        }
    }
}
