<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Reviews\Tier;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Reviews\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

/**
 * Regrades a completed review once findings it posted were settled in a conversation, so its
 * tier matches what is still open on the pull request.
 */
class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateReviewTier';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/reviews/:reviewId/tier')
            ->desc('Update review tier')
            ->groups(['api'])
            ->label('scope', 'public')
            // Keyed on the caller's address: the limit runs before the secret is checked, so any
            // request param would let an unauthenticated caller pick a fresh bucket. {url} is the
            // route pattern, so the review service gets one bucket per route for every project.
            ->label('abuse-key', 'url:{url},ip:{ip}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 10)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->param('reviewId', '', new UID(), 'Review ID.')
            ->param('projectId', '', new UID(), 'Project ID.')
            ->param('tier', '', new WhiteList(['S', 'A', 'B', 'C', 'D', 'F'], true), 'Merge confidence tier.')
            ->param('tierReason', '', new Text(2048, 0), 'Why the review has this tier.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $reviewId,
        string $projectId,
        string $tier,
        string $tierReason,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        callable $getProjectDB,
        Authorization $authorization,
    ): void {
        $this->authenticate($request);

        $project = $this->getProject($projectId, $dbForPlatform, $authorization);
        $dbForProject = $getProjectDB($project);
        $review = $this->getReview($reviewId, $dbForProject, $authorization);

        if ($review->getAttribute('status') !== 'completed') {
            throw new Exception(Exception::REVIEW_STATUS_INVALID, "Review is {$review->getAttribute('status')}, only a completed review has a tier.");
        }

        $authorization->skip(fn () => $dbForProject->updateDocument('reviews', $review->getId(), new Document([
            'tier' => $tier,
            'tierReason' => $tierReason,
        ])));

        $response->noContent();
    }
}
