<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Reviews\Events;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Reviews\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;

/**
 * Steps of the review agent's trace, recorded in batches as the review runs.
 */
class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createReviewEvents';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/reviews/:reviewId/events')
            ->desc('Create review events')
            ->groups(['api'])
            ->label('scope', 'public')
            ->param('reviewId', '', new UID(), 'Review ID.')
            ->param('projectId', '', new UID(), 'Project ID.')
            ->param('events', [], new ArrayList(new Assoc(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Trace events, each with type, at (ISO 8601) and data.')
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
        array $events,
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

        $documents = [];
        foreach ($events as $index => $event) {
            $type = $event['type'] ?? null;
            $at = $event['at'] ?? null;
            $data = $event['data'] ?? [];

            if (!\is_string($type) || $type === '' || \strlen($type) > 64 || !\is_string($at) || !\is_array($data)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Event {$index} is invalid: it needs type, at (ISO 8601) and an object as data.");
            }

            try {
                $time = DateTime::format(new \DateTime($at));
            } catch (\Throwable) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Event {$index} is invalid: at is not an ISO 8601 date.");
            }

            $documents[] = new Document([
                '$id' => Id::unique(),
                'reviewId' => $review->getId(),
                'reviewInternalId' => $review->getSequence(),
                'type' => $type,
                'time' => $time,
                'data' => $data,
            ]);
        }

        if (!empty($documents)) {
            $authorization->skip(fn () => $dbForProject->createDocuments('reviewEvents', $documents));
        }

        $response->noContent();
    }
}
