<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories\Tokens;

use Appwrite\Abuse\Limit;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Reviews\Base;
use Appwrite\Reviews\Provider;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Adapter\Git\Origin;

/**
 * A token for the review service to act on the one repository it reviews. On GitHub and Origin it
 * is an installation token limited to that repository and the permissions reviews need, and
 * Appwrite holds each app's private key; the service never does. On GitLab, Gitea and Bitbucket
 * it is the OAuth token of the account that connected the installation, which Appwrite refreshes.
 */
class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createRepositoryToken';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/reviews/repositories/:repositoryId/tokens')
            ->desc('Create repository token')
            ->groups(['api'])
            ->label('scope', 'public')
            // Keyed on the caller's address: the limit runs before the secret is checked, so any
            // request param would let an unauthenticated caller pick a fresh bucket. {url} is the
            // route pattern, so the review service gets one bucket per route for every project.
            ->label('abuse', [Limit::attempts(['url', 'ip'], APP_LIMIT_WRITE_RATE_DEFAULT * 10, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->param('repositoryId', '', new UID(), 'Repository ID.')
            ->param('projectId', '', new UID(), 'Project ID.')
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('vcsFactory')
            ->inject('installationTokens')
            ->callback($this->action(...));
    }

    public function action(
        string $repositoryId,
        string $projectId,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
    ): void {
        $this->authenticate($request);

        $repository = $this->getRepository($repositoryId, $projectId, $dbForPlatform, $authorization);

        $installation = $authorization->skip(fn () => $dbForPlatform->getDocument('installations', $repository->getAttribute('installationId', '')));
        $provider = Provider::tryFrom($installation->getAttribute('provider', ''));
        if ($installation->isEmpty() || $provider === null) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Code review does not support this repository\'s provider.');
        }

        $providerRepositoryId = $repository->getAttribute('providerRepositoryId', '');

        try {
            $token = match ($provider) {
                // GitHub's resolveReviewThread mutation, which the service uses to resolve the
                // threads of fixed findings, refuses an app token without contents: write.
                Provider::GitHub => $this->getGitHubToken($vcsFactory->fromInstallation($installation), $providerRepositoryId),
                Provider::Origin => $this->getOriginToken($vcsFactory->fromInstallation($installation), $providerRepositoryId),
                // The account that connected the installation over OAuth: its token reaches every
                // repository it can, so the service gets what the installation has, refreshed.
                Provider::GitLab, Provider::Gitea, Provider::Bitbucket => $this->getAccountToken($installation, $installationTokens, $dbForPlatform, $authorization, $vcsFactory),
            };
        } catch (Exception $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to create repository token: ' . $error->getMessage());
        }

        // Who the token acts as: the service mentions it as @<appSlug>, and knows its own
        // comments, reviews and check runs by its id.
        $app = match ($provider) {
            Provider::GitHub => ['appId' => System::getEnv('_APP_VCS_GITHUB_APP_ID', ''), 'appSlug' => System::getEnv('_APP_VCS_GITHUB_APP_NAME', '')],
            Provider::Origin => ['appId' => System::getEnv('_APP_VCS_ORIGIN_CLIENT_ID', ''), 'appSlug' => 'appwrite'],
            // Bitbucket mentions an account by its id, so comments name the reviewer in plain text.
            Provider::Bitbucket => ['appId' => $installation->getAttribute('providerInstallationId', ''), 'appSlug' => 'appwrite'],
            Provider::GitLab, Provider::Gitea => ['appId' => $installation->getAttribute('providerInstallationId', ''), 'appSlug' => $installation->getAttribute('organization', '')],
        };

        // The instance's API, which for a self-hosted GitLab or Gitea only this server knows.
        $endpoint = match ($provider) {
            Provider::GitLab => $vcsFactory->getEndpoint('gitlab') . '/api/v4',
            Provider::Gitea => $vcsFactory->getEndpoint('gitea') . '/api/v1',
            default => '',
        };

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json([
                ...$token,
                ...$app,
                ...($endpoint === '' ? [] : ['endpoint' => $endpoint]),
                'provider' => $provider->value,
            ]);
    }

    /**
     * @return array{token: string, expiresAt: string}
     */
    private function getGitHubToken(Git $vcs, string $providerRepositoryId): array
    {
        if (!$vcs instanceof GitHub) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'The installation is not a GitHub one.');
        }

        return $vcs->createAccessToken(
            [(int) $providerRepositoryId],
            [
                'contents' => 'write',
                'metadata' => 'read',
                'pull_requests' => 'write',
                'issues' => 'write',
                'checks' => 'write',
            ],
        );
    }

    /**
     * @return array{token: string, expiresAt: string}
     */
    private function getOriginToken(Git $vcs, string $providerRepositoryId): array
    {
        if (!$vcs instanceof Origin) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'The installation is not an Origin one.');
        }

        return $vcs->createAccessToken(
            [$providerRepositoryId],
            [
                'repository:contents:read',
                'repository:pull_requests:read',
                'repository:pull_requests:reviews:read',
                'repository:pull_requests:reviews:write',
                'repository:checks:read',
                'repository:checks:write',
            ],
        );
    }

    /**
     * The OAuth token of the account that connected the installation, refreshed when it is about
     * to expire. A token the provider gave no expiry is handed out for an hour, after which the
     * service asks again.
     *
     * @return array{token: string, expiresAt: string}
     */
    private function getAccountToken(Document $installation, InstallationTokens $installationTokens, Database $dbForPlatform, Authorization $authorization, VcsFactory $vcsFactory): array
    {
        $installation = $authorization->skip(fn () => $installationTokens->refreshForInstallation($installation, $dbForPlatform, $vcsFactory));

        $token = $installation->getAttribute('personalAccessToken', '');
        if (empty($token)) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'The installation has no access token. Please reconnect it.');
        }

        $expiry = $installation->getAttribute('personalAccessTokenExpiry', '');

        return [
            'token' => $token,
            'expiresAt' => DateTime::formatTz(empty($expiry) ? DateTime::addSeconds(new \DateTime(), 3600) : $expiry),
        ];
    }
}
