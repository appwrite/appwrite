<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Rollouts\Create as Rollouts;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class Update extends Base
{
    private const string VALIDATE = ':validate';
    private const string COMMIT = ':commit';
    private const string ERROR_IF_IN_REVIEW = 'ERROR_IF_IN_REVIEW';
    private const string NOT_SENT_FOR_REVIEW_REQUIRED = 'Changes cannot be sent for review automatically. Please set the query parameter changesNotSentForReview to true. Once committed, the changes in this edit can be sent for review from the Google Play Console UI.';
    private const string NOT_SENT_FOR_REVIEW_FORBIDDEN = 'Changes are sent for review automatically. The query parameter changesNotSentForReview must not be set.';

    public static function getName(): string
    {
        return 'updateDistributionGoogleEdit';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::APPLICATIONS . '/:packageName/edits/:editId')
            ->desc('Update Google Play edit')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('editId', '', new Text(0), 'Edit ID followed by the custom method, `:validate` or `:commit`.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $packageName, string $editId, Request $request, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $method = match (true) {
            \str_ends_with($editId, self::VALIDATE) => self::VALIDATE,
            \str_ends_with($editId, self::COMMIT) => self::COMMIT,
            default => null,
        };

        if ($method === null) {
            $this->notFound($response, 'notFound', 'Method not found.');
            return;
        }

        $edit = $this->edit($request, $response, $cache, $packageName, \substr($editId, 0, -\strlen($method)));

        if ($edit === null) {
            return;
        }

        if ($method === self::COMMIT) {
            $this->locked($lock, $packageName, fn () => $this->commit($request, $response, $cache, $packageName, $edit));
            return;
        }

        $response->json($this->appEdit($edit));
    }

    /**
     * @param array<string, mixed> $edit
     */
    private function commit(Request $request, Response $response, Cache $cache, string $packageName, array $edit): void
    {
        $review = $this->review($cache, $packageName);
        $notSentForReview = $request->getQuery('changesNotSentForReview', 'false') === 'true';

        if ($review['inReview'] && $request->getQuery('changesInReviewBehavior', '') === self::ERROR_IF_IN_REVIEW) {
            $this->failedPrecondition($response, (string) $edit['id']);
            return;
        }

        if ($review['notSentForReview'] && !$notSentForReview) {
            $this->badRequest($response, self::NOT_SENT_FOR_REVIEW_REQUIRED);
            return;
        }

        if (!$review['notSentForReview'] && $notSentForReview) {
            $this->badRequest($response, self::NOT_SENT_FOR_REVIEW_FORBIDDEN);
            return;
        }

        $key = $this->key(self::RESOURCE_APPLICATION, $packageName);
        $application = $cache->load($key, self::TTL);

        if (!\is_array($application)) {
            $this->notFound($response, 'applicationNotFound', 'Package not found: ' . $packageName . '.');
            return;
        }

        $edit = $this->stage($cache, $packageName, $edit);
        $previous = [];

        foreach ($application['tracks'] ?? [] as $track) {
            $previous[$track['track']] = $track['releases'] ?? [];
        }

        foreach ($edit['tracks'] ?? [] as $track) {
            $releases = $track['releases'] ?? [];

            if ($releases != ($previous[$track['track']] ?? [])) {
                $review['releases'][$track['track']] = $this->summaries($review, $track['track'], $releases, $previous[$track['track']] ?? []);
            }
        }

        foreach (self::CONTENT as $content) {
            $application[$content] = $edit[$content] ?? [];
        }

        $cache->save($key, $application, '', self::TTL);
        $this->saveReview($cache, $packageName, $review);
        $cache->purge($this->key(self::RESOURCE_EDIT, (string) $edit['id']));
        $cache->purge($this->current($packageName, (string) $edit['email']));

        $response->json($this->appEdit($edit));
    }

    /**
     * @param array<string, mixed> $edit
     * @return array<string, mixed>
     */
    private function stage(Cache $cache, string $packageName, array $edit): array
    {
        $key = $this->key(Rollouts::RESOURCE, $packageName);
        $rollout = $cache->load($key, self::TTL);

        if (!\is_array($rollout)) {
            return $edit;
        }

        $index = $this->locate($edit['tracks'] ?? [], (string) ($rollout['track'] ?? ''));
        $staged = false;

        foreach ($index === null ? [] : ($edit['tracks'][$index]['releases'] ?? []) as $position => $release) {
            if (($release['status'] ?? null) === self::STATUS_COMPLETED) {
                $edit['tracks'][$index]['releases'][$position] = [
                    ...$release,
                    'status' => self::STATUS_IN_PROGRESS,
                    'userFraction' => (float) $rollout['fraction'],
                ];
                $staged = true;
            }
        }

        if ($staged) {
            $cache->purge($key);
        }

        return $edit;
    }

    /**
     * @param array{notSentForReview: bool, states: array<string, string>, releases: array<string, list<array<string, mixed>>>} $review
     * @param list<array<string, mixed>> $releases
     * @param list<array<string, mixed>> $previous
     * @return list<array<string, mixed>>
     */
    private function summaries(array $review, string $track, array $releases, array $previous): array
    {
        $existing = [];

        foreach ($review['releases'][$track] ?? [] as $summary) {
            $codes = \array_map(fn (array $artifact): string => (string) $artifact['versionCode'], $summary['activeArtifacts'] ?? []);
            $existing[\implode(',', $codes)] = $summary;
        }

        $unchanged = [];

        foreach ($previous as $release) {
            $unchanged[$this->signature($release)] = $release;
        }

        $summaries = [];

        foreach ($releases as $release) {
            $signature = $this->signature($release);

            if (isset($existing[$signature]) && ($unchanged[$signature] ?? null) == $release) {
                $summaries[] = $existing[$signature];
                continue;
            }

            $codes = \array_map(\strval(...), $release['versionCodes'] ?? []);

            $summaries[] = [
                'releaseName' => (string) ($release['name'] ?? $codes[0] ?? ''),
                'track' => $track,
                'activeArtifacts' => \array_map(fn (string $code): array => ['versionCode' => (int) $code], $codes),
                'releaseLifecycleState' => match (true) {
                    ($release['status'] ?? '') === self::STATUS_DRAFT => self::STATE_DRAFT,
                    $review['notSentForReview'] => self::STATE_NOT_SENT_FOR_REVIEW,
                    default => $review['states'][$track] ?? self::STATE_PUBLISHED,
                },
            ];
        }

        return $summaries;
    }

    /**
     * @param array<string, mixed> $release
     */
    private function signature(array $release): string
    {
        return \implode(',', \array_map(\strval(...), $release['versionCodes'] ?? []));
    }
}
