<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories\Tokens;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Reviews\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

/**
 * A GitHub installation token for the review service, limited to the one repository it reviews.
 * Appwrite holds the GitHub App's private key; the service never does.
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
            ->label('abuse-key', 'url:{url},ip:{ip}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 10)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->param('repositoryId', '', new UID(), 'Repository ID.')
            ->param('projectId', '', new UID(), 'Project ID.')
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('vcsFactory')
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
    ): void {
        $this->authenticate($request);

        $repository = $this->getRepository($repositoryId, $projectId, $dbForPlatform, $authorization);

        $installation = $authorization->skip(fn () => $dbForPlatform->getDocument('installations', $repository->getAttribute('installationId', '')));

        $vcs = $vcsFactory->fromInstallation($installation);
        if (!$vcs instanceof GitHub) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Code review supports GitHub repositories only.');
        }

        try {
            $token = $vcs->createAccessToken([(int) $repository->getAttribute('providerRepositoryId')]);
        } catch (\Throwable $error) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to create repository token: ' . $error->getMessage());
        }

        // The app the token acts as: the service mentions it as @<appSlug>, and knows its own
        // comments, reviews and check runs by it.
        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json([
                ...$token,
                'appId' => System::getEnv('_APP_VCS_GITHUB_APP_ID', ''),
                'appSlug' => System::getEnv('_APP_VCS_GITHUB_APP_NAME', ''),
            ]);
    }
}
