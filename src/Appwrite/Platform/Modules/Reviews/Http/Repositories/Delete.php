<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
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
            ->label('sdk', new Method(
                namespace: 'reviews',
                group: 'repositories',
                name: 'deleteRepository',
                description: <<<EOT
                Turn off code review for a repository. Its past reviews stay in the project.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
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
