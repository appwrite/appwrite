<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Permission;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git\GitHub;

/**
 * Turns code review on for a repository: from now on its pull requests are queued for review.
 * Not in the SDKs yet; the Console section that calls it adds the SDK method.
 */
class Create extends Action
{
    use HTTP;
    use Permission;

    public static function getName()
    {
        return 'createReviewRepository';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/reviews/repositories')
            ->desc('Create repository')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.write')
            ->label('audits.event', 'repository.create')
            ->label('audits.resource', 'repository/{response.$id}')
            ->param('installationId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Appwrite installation ID the repository is connected through.', false, ['dbForPlatform'])
            ->param('providerRepositoryId', '', new Text(20), 'GitHub repository ID.')
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('vcsFactory')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        string $providerRepositoryId,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
        VcsFactory $vcsFactory,
    ): void {
        // GitHub repository ids are numeric, and this one becomes part of the row's id.
        if (!\ctype_digit($providerRepositoryId)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Param "providerRepositoryId" must be a numeric GitHub repository ID.');
        }

        $installation = $authorization->skip(fn () => $dbForPlatform->getDocument('installations', $installationId));

        if ($installation->isEmpty() || $installation->getAttribute('projectId') !== $project->getId()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if ($installation->getAttribute('provider') !== 'github') {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Code review supports GitHub repositories only.');
        }

        // One project reviews a repository, so a pull request is never reviewed twice.
        $existing = $authorization->skip(fn () => $dbForPlatform->findOne('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::equal('resourceType', [RESOURCE_TYPE_REVIEWS]),
        ]));
        if (!$existing->isEmpty()) {
            throw new Exception(Exception::REVIEW_REPOSITORY_ALREADY_EXISTS);
        }

        // The installation has to reach the repository: a repository id alone would let a
        // project claim the reviews of another organization's repository.
        $vcs = $vcsFactory->fromInstallation($installation);
        if (!$vcs instanceof GitHub) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Code review supports GitHub repositories only.');
        }

        try {
            $vcs->getRepositoryName($providerRepositoryId);
        } catch (\Throwable) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        // The id is derived from the GitHub repository, so two concurrent creates collide on it
        // instead of both passing the check above.
        try {
            $repository = $authorization->skip(fn () => $dbForPlatform->createDocument('repositories', new Document([
                '$id' => Id::custom('reviews-' . $providerRepositoryId),
                '$permissions' => $this->getPermissions($project->getAttribute('teamId', ''), $project->getId()),
                'installationId' => $installation->getId(),
                'installationInternalId' => $installation->getSequence(),
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getSequence(),
                'providerRepositoryId' => $providerRepositoryId,
                // Reviews belong to the project rather than to one of its resources.
                'resourceId' => $project->getId(),
                'resourceInternalId' => $project->getSequence(),
                'resourceType' => RESOURCE_TYPE_REVIEWS,
                'providerPullRequestIds' => [],
            ])));
        } catch (DuplicateException) {
            throw new Exception(Exception::REVIEW_REPOSITORY_ALREADY_EXISTS);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($repository, Response::MODEL_REVIEW_REPOSITORY);
    }
}
