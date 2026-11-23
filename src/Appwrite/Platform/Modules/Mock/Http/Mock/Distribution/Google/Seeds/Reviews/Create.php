<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Reviews;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    public static function getName(): string
    {
        return 'createDistributionGoogleSeedReview';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/reviews')
            ->desc('Seed Google Play review state')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('track', '', new Text(0, 0), 'Track the state applies to. Required with state.', true)
            ->param('state', null, new Nullable(new WhiteList(self::STATES, true)), 'Release lifecycle state for the track, applied to its existing release summaries and to releases committed to it later.', true)
            ->param('inReview', null, new Nullable(new Boolean(true)), 'Whether the application has changes in review, so a commit with ERROR_IF_IN_REVIEW fails.', true)
            ->param('notSentForReview', null, new Nullable(new Boolean(true)), 'Whether commits must set changesNotSentForReview, as after a rejection.', true)
            ->param('draft', null, new Nullable(new Boolean(true)), 'Whether the application is a draft app that only accepts draft releases.', true)
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $packageName, string $track, ?string $state, mixed $inReview, mixed $notSentForReview, mixed $draft, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        if ($state !== null && $track === '') {
            throw new Exception(Exception::GENERAL_MOCK, 'A state needs a track');
        }

        if (!\is_array($cache->load($this->key(self::RESOURCE_APPLICATION, $packageName), self::TTL))) {
            throw new Exception(Exception::GENERAL_MOCK, 'Seed the application first');
        }

        $flags = \array_filter([
            'inReview' => $inReview,
            'notSentForReview' => $notSentForReview,
            'draft' => $draft,
        ], fn (mixed $value): bool => $value !== null);

        $review = $this->locked($lock, $packageName, fn (): array => $this->merge($cache, $packageName, $track, $state, $flags));

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($review);
    }

    /**
     * @param array<string, mixed> $flags
     * @return array<string, mixed>
     */
    private function merge(Cache $cache, string $packageName, string $track, ?string $state, array $flags): array
    {
        $review = $this->review($cache, $packageName);

        foreach ($flags as $flag => $value) {
            $review[$flag] = \filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if ($state !== null) {
            $review['states'][$track] = $state;
            $review['releases'][$track] = \array_map(
                fn (array $summary): array => [...$summary, 'releaseLifecycleState' => $state],
                $review['releases'][$track] ?? [],
            );
        }

        $this->saveReview($cache, $packageName, $review);

        return $review;
    }
}
