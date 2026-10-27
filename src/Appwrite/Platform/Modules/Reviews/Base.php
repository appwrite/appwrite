<?php

namespace Appwrite\Platform\Modules\Reviews;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;

/**
 * The internal API the review service (appwrite/hansi) reports through. The service is not
 * a project user: it proves itself with the secret both sides share, and names the project in
 * the body, so its calls are never metered as the project's own API requests.
 */
class Base extends Action
{
    public const string SECRET_HEADER = 'x-appwrite-reviews-secret';

    protected function authenticate(Request $request): void
    {
        $secret = System::getEnv('_APP_REVIEWS_SECRET', '');
        $provided = $request->getHeaderLine(self::SECRET_HEADER, '');

        // An unset secret refuses every call rather than accepting an empty header.
        if ($secret === '' || !\hash_equals($secret, $provided)) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid reviews secret.');
        }
    }

    protected function getProject(string $projectId, Database $dbForPlatform, Authorization $authorization): Document
    {
        $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        return $project;
    }

    protected function getReview(string $reviewId, Database $dbForProject, Authorization $authorization): Document
    {
        $review = $authorization->skip(fn () => $dbForProject->getDocument('reviews', $reviewId));

        if ($review->isEmpty()) {
            throw new Exception(Exception::REVIEW_NOT_FOUND);
        }

        return $review;
    }
}
