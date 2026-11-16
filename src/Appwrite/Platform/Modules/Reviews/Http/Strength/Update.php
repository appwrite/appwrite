<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Strength;

use Appwrite\Platform\Action;
use Appwrite\Reviews\Strength;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

/**
 * Sets how hard code review looks at every pull request in the project, one setting for all of
 * its repositories. Reviews already queued keep the strength they were queued at.
 */
class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateReviewStrength';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/reviews/strength')
            ->desc('Update review strength')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.write')
            ->label('audits.event', 'project.update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'reviews',
                group: null,
                name: 'updateStrength',
                description: <<<EOT
                Change how hard code review looks at the pull requests of every repository in the project, and what each review costs. basic reviews with cheaper models and reads the code only, normal is the default, expert reviews with stronger models and subagents. Reviews already queued keep the strength they were queued at.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
                // Hidden until code review is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('strength', '', new WhiteList(Strength::values(), true), 'How hard pull requests are reviewed. Possible values: basic, normal, expert.', enum: new Enum(name: 'ReviewStrength'))
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $strength,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'reviewStrength' => $strength,
        ])));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
