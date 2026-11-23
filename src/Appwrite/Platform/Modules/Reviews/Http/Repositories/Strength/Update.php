<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Repositories\Strength;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Reviews\Strength;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

/**
 * Changes how hard a repository's pull requests are reviewed. Reviews already queued keep the
 * strength they were queued at.
 */
class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateReviewRepositoryStrength';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/reviews/repositories/:repositoryId/strength')
            ->desc('Update repository strength')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.write')
            ->label('audits.event', 'repository.update')
            ->label('audits.resource', 'repository/{request.repositoryId}')
            ->label('sdk', new Method(
                namespace: 'reviews',
                group: 'repositories',
                name: 'updateRepositoryStrength',
                description: <<<EOT
                Change how hard a repository's pull requests are reviewed, and what each review costs. basic reviews with cheaper models and reads the code only, normal is the default, expert reviews with stronger models and subagents. Reviews already queued keep the strength they were queued at.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_REVIEW_REPOSITORY,
                    )
                ],
                // Hidden until code review is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('repositoryId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Repository ID.', false, ['dbForPlatform'])
            ->param('strength', '', new WhiteList(Strength::values(), true), 'How hard pull requests are reviewed. Possible values: basic, normal, expert.', enum: new Enum(name: 'ReviewStrength'))
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $repositoryId,
        string $strength,
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

        $repository = $authorization->skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), new Document([
            'strength' => $strength,
        ])));

        $response->dynamic($repository, Response::MODEL_REVIEW_REPOSITORY);
    }
}
