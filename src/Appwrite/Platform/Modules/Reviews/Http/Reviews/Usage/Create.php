<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Reviews\Usage;

use Appwrite\Platform\Modules\Reviews\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;

/**
 * Model calls a review made, one row each, for metering what reviews cost.
 */
class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createReviewUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/reviews/:reviewId/usage')
            ->desc('Create review usage')
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
            ->param('usage', [], new ArrayList(new Assoc(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Model calls, each with role, provider, model, inputTokens, outputTokens, durationMs and optional cachedInputTokens and reasoningTokens.')
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
        array $usage,
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

        $documents = $this->prepareUsage($usage, 'reviews', $review);

        if (!empty($documents)) {
            $authorization->skip(fn () => $dbForProject->createDocuments('reviewUsage', $documents));
        }

        $response->noContent();
    }
}
