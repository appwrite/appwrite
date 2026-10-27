<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Reviews;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
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
            ->param('reviewId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Review ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $reviewId,
        Response $response,
        Database $dbForProject,
        Authorization $authorization,
    ): void {
        $review = $authorization->skip(fn () => $dbForProject->getDocument('reviews', $reviewId));

        if ($review->isEmpty()) {
            throw new Exception(Exception::REVIEW_NOT_FOUND);
        }

        $findings = $authorization->skip(fn () => $dbForProject->find('reviewFindings', [
            Query::equal('reviewInternalId', [$review->getSequence()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        $review->setAttribute('findings', $findings);

        $response->dynamic($review, Response::MODEL_REVIEW);
    }
}
