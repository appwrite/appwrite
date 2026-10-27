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
        $this->authenticate($request, $authorization);

        $repository = $authorization->skip(fn () => $dbForPlatform->getDocument('repositories', $repositoryId));

        // Only a repository the project has code review on, so the secret cannot reach the
        // rest of the GitHub App's installations.
        if (
            $repository->isEmpty()
            || $repository->getAttribute('resourceType') !== RESOURCE_TYPE_REVIEWS
            || $repository->getAttribute('projectId') !== $projectId
        ) {
            throw new Exception(Exception::REPOSITORY_NOT_FOUND);
        }

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

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($token);
    }
}
