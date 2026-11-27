<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\BetaAppReviewSubmissions;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Client\Client;
use Utopia\System\System;
use Utopia\Validator\Assoc;

class Create extends Base
{
    private const string TYPE = 'betaAppReviewSubmissions';
    private const string CODE_STATE = 'STATE_ERROR';
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'relationships'],
        'relationships' => ['build'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleBetaAppReviewSubmission';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/betaAppReviewSubmissions')
            ->desc('Create App Store Connect beta app review submission')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Beta app review submission resource with a `build` relationship.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->inject('clientForWebhooks')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(array $data, Request $request, Response $response, Cache $cache, Lock $lock, Client $clientForWebhooks): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $data = \array_intersect_key($data, \array_flip(self::ALLOWED_KEYS['data']));
        $relationships = \is_array($data['relationships'] ?? null) ? \array_intersect_key($data['relationships'], \array_flip(self::ALLOWED_KEYS['relationships'])) : [];

        if (($data['type'] ?? null) !== self::TYPE) {
            $this->invalid($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::TYPE . "'.", '/data/type');
            return;
        }

        $linkage = $relationships['build']['data'] ?? null;

        if (!\is_array($linkage) || ($linkage['type'] ?? null) !== 'builds' || !\is_string($linkage['id'] ?? null) || $linkage['id'] === '') {
            $this->invalid($response, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide a value for the relationship 'build' with this request", '/data/relationships/build');
            return;
        }

        $owned = $this->betaBuild($cache, $key['issuerId'], $linkage['id']);

        if ($owned === null) {
            $this->invalid($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The build '{$linkage['id']}' does not exist.", '/data/relationships/build');
            return;
        }

        $build = $owned['build'];

        if ($this->betaSubmission($cache, $build['id']) !== null) {
            $this->submitted($response);
            return;
        }

        $detail = $this->betaDetail($cache, $lock, $clientForWebhooks, $owned['appId'], $build);
        $state = $detail['externalBuildState'];

        if ($state !== self::BETA_READY_FOR_SUBMISSION && !isset(self::BETA_REVIEW_STATES[$state])) {
            $this->invalid($response, self::CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The build cannot be submitted for beta review while its external build state is {$state}.", '/data/relationships/build');
            return;
        }

        $submission = [
            'type' => self::TYPE,
            'id' => $this->uuid(),
            'attributes' => [
                'betaReviewState' => self::BETA_REVIEW_STATES[$state] ?? self::BETA_REVIEW_STATES[self::BETA_WAITING_FOR_REVIEW],
                'submittedDate' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            ],
        ];

        $created = $this->locked($lock, self::CACHE_BETA_DETAIL . $build['id'], function () use ($cache, $build, $submission): bool {
            if ($this->betaSubmission($cache, $build['id']) !== null) {
                return false;
            }

            $cache->save(self::CACHE_BETA_SUBMISSION . $build['id'], $submission, '', self::TTL);

            return true;
        });

        if (!$created) {
            $this->submitted($response);
            return;
        }

        if ($state === self::BETA_READY_FOR_SUBMISSION) {
            $this->updateBetaDetail($cache, $lock, $clientForWebhooks, $owned['appId'], $build, self::BETA_WAITING_FOR_REVIEW, null);
        }

        $this->respond($response, [
            'data' => $submission,
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }

    private function submitted(Response $response): void
    {
        $this->invalid($response, self::CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', 'The build has already been submitted for beta review.', '/data/relationships/build');
    }

    private function invalid(Response $response, string $code, string $title, string $detail, string $pointer): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, ['pointer' => $pointer]);
    }
}
