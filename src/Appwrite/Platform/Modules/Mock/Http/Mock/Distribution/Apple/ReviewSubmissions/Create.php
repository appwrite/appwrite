<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\ReviewSubmissions;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Assoc;

class Create extends Base
{
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'attributes', 'relationships'],
        'attributes' => ['platform'],
        'relationships' => ['app'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleReviewSubmission';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/reviewSubmissions')
            ->desc('Create App Store Connect review submission')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Review submission resource with an optional `platform` attribute and an `app` relationship.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(array $data, Request $request, Response $response, Cache $cache, Lock $lock): void
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
        $attributes = \is_array($data['attributes'] ?? null) ? \array_intersect_key($data['attributes'], \array_flip(self::ALLOWED_KEYS['attributes'])) : [];
        $relationships = \is_array($data['relationships'] ?? null) ? \array_intersect_key($data['relationships'], \array_flip(self::ALLOWED_KEYS['relationships'])) : [];

        if (($data['type'] ?? null) !== self::VERSION_TYPE_SUBMISSION) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::VERSION_TYPE_SUBMISSION . "'.", '/data/type');
            return;
        }

        if (($attributes['platform'] ?? self::VERSION_PLATFORM) !== self::VERSION_PLATFORM) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'platform' has an invalid value.", '/data/attributes/platform');
            return;
        }

        $appId = $this->versionLinkage($relationships, 'app', 'apps');

        if ($appId === null) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide a value for the relationship 'app' with this request", '/data/relationships/app');
            return;
        }

        if (!$this->owns($cache, $key['issuerId'], $appId)) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The app '{$appId}' does not exist.", '/data/relationships/app');
            return;
        }

        foreach ($this->versionSubmissions($cache, $appId) as $existing) {
            if (\in_array($existing['attributes']['state'], self::VERSION_SUBMISSION_OPEN, true)) {
                $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The app already has a review submission in state {$existing['attributes']['state']}.");
                return;
            }
        }

        $submission = [
            'type' => self::VERSION_TYPE_SUBMISSION,
            'id' => $this->uuid(),
            'attributes' => [
                'platform' => self::VERSION_PLATFORM,
                'submittedDate' => null,
                'state' => self::VERSION_SUBMISSION_READY_FOR_REVIEW,
            ],
            self::INTERNAL => [
                'appId' => $appId,
                'versionId' => null,
                'items' => [],
            ],
        ];

        $this->saveVersionSubmission($cache, $lock, $submission);

        $this->respond($response, [
            'data' => $this->versionSubmissionResource($submission),
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }
}
