<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Reviews;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getReview';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reviews/:reviewId')
            ->desc('Get review')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.read')
            ->label('sdk', new Method(
                namespace: 'reviews',
                group: 'reviews',
                name: 'get',
                description: <<<EOT
                Get a code review by its unique ID, with the findings it posted.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_REVIEW,
                    )
                ],
            ))
            ->param('reviewId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Review ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $reviewId,
        Response $response,
        Database $dbForProject,
    ): void {
        $review = $dbForProject->getDocument('reviews', $reviewId);

        if ($review->isEmpty()) {
            throw new Exception(Exception::REVIEW_NOT_FOUND);
        }

        $findings = $dbForProject->find('reviewFindings', [
            Query::equal('reviewInternalId', [$review->getSequence()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        $review->setAttribute('findings', $findings);

        $response->dynamic($review, Response::MODEL_REVIEW);
    }
}
