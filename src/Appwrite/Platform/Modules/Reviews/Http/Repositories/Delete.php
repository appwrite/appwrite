<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

/**
 * Turns code review off for a repository. Its past reviews stay in the project.
 */
class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteReviewRepository';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/reviews/repositories/:repositoryId')
            ->desc('Delete repository')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.write')
            ->label('audits.event', 'repository.delete')
            ->label('audits.resource', 'repository/{request.repositoryId}')
            ->param('repositoryId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Repository ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $repositoryId,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $repository = $authorization->skip(fn () => $dbForPlatform->getDocument('repositories', $repositoryId));

        if (
            $repository->isEmpty()
            || $repository->getAttribute('projectInternalId') !== $project->getSequence()
            || $repository->getAttribute('resourceType') !== RESOURCE_TYPE_REVIEWS
        ) {
            throw new Exception(Exception::REPOSITORY_NOT_FOUND);
        }

        $authorization->skip(fn () => $dbForPlatform->deleteDocument('repositories', $repository->getId()));

        $response->noContent();
    }
}
