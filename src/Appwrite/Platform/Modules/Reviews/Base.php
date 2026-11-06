<?php

namespace Appwrite\Platform\Modules\Reviews;

use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Utopia\Request;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Query;
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

    /**
     * Token totals of `reviewUsage` rows as the project's usage metrics, overall and per model,
     * broken down by the repository the review or chat ran on. These calls come from the review
     * service, not the project, so the metrics are published for the named project directly.
     *
     * @param array<Document> $documents
     */
    protected function publishTokens(Document $project, Document $resource, array $documents, UsagePublisher $publisherForUsage): void
    {
        $usage = $this->getRepositoryUsage($resource);

        foreach ($documents as $document) {
            $model = $document->getAttribute('model');
            $input = $document->getAttribute('inputTokens', 0);
            $output = $document->getAttribute('outputTokens', 0);

            $usage
                ->addMetric(METRIC_REVIEWS_TOKENS_INPUT, $input)
                ->addMetric(METRIC_REVIEWS_TOKENS_OUTPUT, $output)
                ->addMetric(METRIC_REVIEWS_TOKENS_CACHED_INPUT, $document->getAttribute('cachedInputTokens', 0))
                ->addMetric(METRIC_REVIEWS_TOKENS_REASONING, $document->getAttribute('reasoningTokens', 0))
                ->addMetric(\str_replace('{model}', $model, METRIC_REVIEWS_MODEL_TOKENS_INPUT), $input)
                ->addMetric(\str_replace('{model}', $model, METRIC_REVIEWS_MODEL_TOKENS_OUTPUT), $output);
        }

        $this->publishUsage($project, $usage, $publisherForUsage);
    }

    /**
     * Usage of a review or chat, attributed to the repository it ran on.
     */
    protected function getRepositoryUsage(Document $resource): UsageContext
    {
        $usage = new UsageContext();

        return $usage
            ->setService('reviews')
            ->setResource('repository')
            ->setResourceId($resource->getAttribute('repositoryId', ''))
            ->setResourceInternalId($resource->getAttribute('repositoryInternalId', ''));
    }

    protected function publishUsage(Document $project, UsageContext $usage, UsagePublisher $publisherForUsage): void
    {
        if ($usage->isEmpty()) {
            return;
        }

        $publisherForUsage->enqueue(new UsageMessage(
            project: $project,
            metrics: $usage->getMetrics(),
        ));
    }

    /**
     * A repository the project has code review on. Any other row is not found, so the secret
     * cannot reach the rest of the GitHub App's installations.
     */
    protected function getRepository(string $repositoryId, string $projectId, Database $dbForPlatform, Authorization $authorization): Document
    {
        $repository = $authorization->skip(fn () => $dbForPlatform->getDocument('repositories', $repositoryId));

        if (
            $repository->isEmpty()
            || $repository->getAttribute('resourceType') !== RESOURCE_TYPE_REVIEWS
            || $repository->getAttribute('projectId') !== $projectId
        ) {
            throw new Exception(Exception::REPOSITORY_NOT_FOUND);
        }

        return $repository;
    }

    /**
     * Reviews of a pull request, newest first.
     *
     * @param array<Query> $queries
     * @return array<Document>
     */
    protected function listPullReviews(Document $repository, int $pullNumber, array $queries, Database $dbForProject, Authorization $authorization): array
    {
        return $authorization->skip(fn () => $dbForProject->find('reviews', [
            Query::equal('repositoryInternalId', [$repository->getSequence()]),
            Query::equal('providerPullRequestId', [(string) $pullNumber]),
            ...$queries,
            Query::orderDesc('$sequence'),
            Query::limit(APP_LIMIT_REVIEWS_PULL),
        ]));
    }

    /**
     * A review as the review service reads it back (`ReviewRecord` in appwrite/hansi).
     *
     * @return array<string, mixed>
     */
    protected function toReviewRecord(Document $review): array
    {
        $record = [
            'id' => $review->getId(),
            'status' => $review->getAttribute('status'),
            'trigger' => $review->getAttribute('trigger'),
            'headSha' => $review->getAttribute('providerCommitHash', ''),
            'summary' => $review->getAttribute('summary', ''),
            'tierReason' => $review->getAttribute('tierReason', ''),
            'walkthrough' => $review->getAttribute('walkthrough', []),
        ];

        foreach (['verdict', 'tier', 'completedAt'] as $key) {
            $value = $review->getAttribute($key, '');
            if (!empty($value)) {
                $record[$key] = $value;
            }
        }

        return $record;
    }

    /**
     * A finding as the review service reads it back (`FindingRecord` in appwrite/hansi).
     *
     * @return array<string, mixed>
     */
    protected function toFindingRecord(Document $finding): array
    {
        $commentId = $finding->getAttribute('providerCommentId', '');

        return [
            'id' => $finding->getId(),
            'reviewId' => $finding->getAttribute('reviewId'),
            'path' => $finding->getAttribute('path'),
            'startLine' => $finding->getAttribute('startLine'),
            'endLine' => $finding->getAttribute('endLine'),
            'severity' => $finding->getAttribute('severity'),
            'category' => $finding->getAttribute('category'),
            'title' => $finding->getAttribute('title'),
            'body' => $finding->getAttribute('body'),
            'suggestion' => $finding->getAttribute('suggestion') ?: null,
            'status' => $finding->getAttribute('status', 'posted'),
            'dropReason' => $finding->getAttribute('dropReason') ?: null,
            'providerCommentId' => $commentId === '' || $commentId === null ? null : (int) $commentId,
            'createdAt' => $finding->getCreatedAt(),
        ];
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
