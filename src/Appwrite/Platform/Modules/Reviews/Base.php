<?php

namespace Appwrite\Platform\Modules\Reviews;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
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

    /**
     * Model calls as `reviewUsage` rows for a review or a chat answer, after checking each entry.
     *
     * @param array<mixed> $usage
     * @return array<Document>
     */
    protected function prepareUsage(array $usage, string $resourceType, Document $resource): array
    {
        $strings = [
            'role' => 32,
            'provider' => 64,
            'model' => 128,
        ];
        $counts = [
            'inputTokens' => true,
            'outputTokens' => true,
            'durationMs' => true,
            'cachedInputTokens' => false,
            'reasoningTokens' => false,
        ];

        $documents = [];
        foreach ($usage as $index => $call) {
            $valid = \is_array($call);
            foreach ($strings as $key => $size) {
                $value = $call[$key] ?? null;
                $valid = $valid && \is_string($value) && $value !== '' && \strlen($value) <= $size;
            }
            foreach ($counts as $key => $required) {
                $value = $call[$key] ?? ($required ? null : 0);
                $valid = $valid && \is_int($value) && $value >= 0;
            }

            if (!$valid) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Usage {$index} is invalid: it needs role, provider, model, inputTokens, outputTokens and durationMs, with counts as non-negative integers.");
            }

            $documents[] = new Document([
                '$id' => Id::unique(),
                'resourceType' => $resourceType,
                'resourceId' => $resource->getId(),
                'resourceInternalId' => $resource->getSequence(),
                'role' => $call['role'],
                'provider' => $call['provider'],
                'model' => $call['model'],
                'inputTokens' => $call['inputTokens'],
                'outputTokens' => $call['outputTokens'],
                'cachedInputTokens' => $call['cachedInputTokens'] ?? 0,
                'reasoningTokens' => $call['reasoningTokens'] ?? 0,
                'duration' => $call['durationMs'],
            ]);
        }

        return $documents;
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
