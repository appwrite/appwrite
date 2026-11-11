<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Learnings;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

/**
 * Forgets a learning: later reviews and answers no longer follow it.
 */
class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteReviewLearning';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/reviews/learnings/:learningId')
            ->desc('Delete learning')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.write')
            ->label('audits.event', 'learning.delete')
            ->label('audits.resource', 'learning/{request.learningId}')
            ->label('sdk', new Method(
                namespace: 'reviews',
                group: 'learnings',
                name: 'deleteLearning',
                description: <<<EOT
                Delete a learning. Later reviews and answers no longer follow it.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
                // Hidden until code review is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('learningId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Learning ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $learningId,
        Response $response,
        Database $dbForProject,
    ): void {
        $learning = $dbForProject->getDocument('reviewLearnings', $learningId);

        if ($learning->isEmpty()) {
            throw new Exception(Exception::REVIEW_LEARNING_NOT_FOUND);
        }

        $dbForProject->deleteDocument('reviewLearnings', $learning->getId());

        $response->noContent();
    }
}
