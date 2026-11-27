<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\ReviewSubmissionItems;

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
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'relationships'],
        'relationships' => ['reviewSubmission', 'appStoreVersion'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleReviewSubmissionItem';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/reviewSubmissionItems')
            ->desc('Create App Store Connect review submission item')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Review submission item resource with `reviewSubmission` and `appStoreVersion` relationships.')
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

        if (($data['type'] ?? null) !== self::VERSION_TYPE_ITEM) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::VERSION_TYPE_ITEM . "'.", '/data/type');
            return;
        }

        $submissionId = $this->versionLinkage($relationships, 'reviewSubmission', self::VERSION_TYPE_SUBMISSION);
        $versionId = $this->versionLinkage($relationships, 'appStoreVersion', self::VERSION_TYPE);

        if ($submissionId === null || $versionId === null) {
            $name = $submissionId === null ? 'reviewSubmission' : 'appStoreVersion';
            $this->versionConflict($response, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide a value for the relationship '{$name}' with this request", '/data/relationships/' . $name);
            return;
        }

        $submission = $this->versionSubmission($cache, $submissionId);
        $version = $this->version($cache, $versionId);

        if ($submission === null || !$this->owns($cache, $key['issuerId'], $submission[self::INTERNAL]['appId'])) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The review submission '{$submissionId}' does not exist.", '/data/relationships/reviewSubmission');
            return;
        }

        if ($version === null || $version[self::INTERNAL]['appId'] !== $submission[self::INTERNAL]['appId']) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The version '{$versionId}' does not exist.", '/data/relationships/appStoreVersion');
            return;
        }

        if ($submission[self::INTERNAL]['versionId'] === $versionId) {
            $this->versionConflict($response, self::VERSION_CODE_DUPLICATE, 'The provided entity includes a relationship with an invalid value', 'The version is already an item of this review submission.', '/data/relationships/appStoreVersion');
            return;
        }

        if ($submission['attributes']['state'] !== self::VERSION_SUBMISSION_READY_FOR_REVIEW || $submission[self::INTERNAL]['versionId'] !== null) {
            $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', 'The review submission cannot take another version.');
            return;
        }

        if (!\in_array($version['attributes']['appVersionState'], self::VERSION_EDITABLE, true)) {
            $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The version is {$version['attributes']['appVersionState']} and cannot be submitted for review.");
            return;
        }

        if ($version[self::INTERNAL]['buildId'] === null) {
            $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', 'Submit for review errors found. The version has no build.');
            return;
        }

        $build = $this->build($cache, $version[self::INTERNAL]['buildId']);

        if ($build !== null && ($build['attributes']['usesNonExemptEncryption'] ?? null) === null) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute 'usesNonExemptEncryption' with this request", '/data/attributes/usesNonExemptEncryption');
            return;
        }

        $item = [
            'id' => $this->uuid(),
            'attributes' => ['state' => self::VERSION_SUBMISSION_READY_FOR_REVIEW],
            'versionId' => $versionId,
        ];

        $submission[self::INTERNAL]['items'][] = $item;
        $submission[self::INTERNAL]['versionId'] = $versionId;

        $this->saveVersionSubmission($cache, $lock, $submission);
        $this->versionTransition($cache, $lock, $clientForWebhooks, $version, self::VERSION_READY_FOR_REVIEW);

        $this->respond($response, [
            'data' => $this->versionItemResource($item),
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }
}
