<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\ReviewSubmissions;

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
use Utopia\Validator\Text;

class Update extends Base
{
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'id', 'attributes'],
        'attributes' => ['platform', 'submitted', 'canceled'],
    ];
    private const array CANCELABLE = [
        self::VERSION_SUBMISSION_WAITING_FOR_REVIEW,
        self::VERSION_SUBMISSION_IN_REVIEW,
        self::VERSION_SUBMISSION_UNRESOLVED_ISSUES,
    ];

    public static function getName(): string
    {
        return 'updateDistributionAppleReviewSubmission';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath(self::PREFIX . '/v1/reviewSubmissions/:reviewSubmissionId')
            ->desc('Update App Store Connect review submission')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('reviewSubmissionId', '', new Text(64), 'Review submission ID.')
            ->param('data', [], new Assoc(), 'Review submission resource with `submitted: true` or `canceled: true`.')
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
    public function action(string $reviewSubmissionId, array $data, Request $request, Response $response, Cache $cache, Lock $lock, Client $clientForWebhooks): void
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

        $submission = $this->versionSubmission($cache, $reviewSubmissionId);

        if ($submission === null || !$this->owns($cache, $key['issuerId'], $submission[self::INTERNAL]['appId'])) {
            $this->notFound($response, self::VERSION_TYPE_SUBMISSION, $reviewSubmissionId);
            return;
        }

        if (($data['type'] ?? null) !== self::VERSION_TYPE_SUBMISSION || ($data['id'] ?? null) !== $reviewSubmissionId) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', 'The type and id must match the review submission being updated.', '/data');
            return;
        }

        $submitted = ($attributes['submitted'] ?? null) === true;
        $canceled = ($attributes['canceled'] ?? null) === true;

        if ($submitted === $canceled) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "Set exactly one of 'submitted' or 'canceled' to true.", '/data/attributes');
            return;
        }

        $state = $submission['attributes']['state'];
        $version = $submission[self::INTERNAL]['versionId'] === null ? null : $this->version($cache, $submission[self::INTERNAL]['versionId']);

        if ($submitted && ($state !== self::VERSION_SUBMISSION_READY_FOR_REVIEW || $version === null)) {
            $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', $version === null ? 'The review submission has no items.' : "The review submission is {$state} and cannot be submitted.");
            return;
        }

        if ($canceled && !\in_array($state, self::CANCELABLE, true)) {
            $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The review submission is {$state} and cannot be canceled.");
            return;
        }

        if ($submitted) {
            $submission['attributes']['state'] = self::VERSION_SUBMISSION_WAITING_FOR_REVIEW;
            $submission['attributes']['submittedDate'] = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED);
            $this->saveVersionSubmission($cache, $lock, $submission);

            $review = $this->versionReview($cache, $submission[self::INTERNAL]['appId'], $version['attributes']['versionString']);
            $version[self::INTERNAL]['phasedDay'] = $review['phasedDay'] ?? null;

            if ($review !== null) {
                $this->saveVersionReview($cache, $lock, $submission[self::INTERNAL]['appId'], $version['attributes']['versionString'], null);
            }

            $this->versionTransition($cache, $lock, $clientForWebhooks, $version, $review['state'] ?? self::VERSION_WAITING_FOR_REVIEW);
        } else {
            $submission['attributes']['state'] = self::VERSION_SUBMISSION_COMPLETE;
            $this->saveVersionSubmission($cache, $lock, $submission);

            if ($version !== null) {
                $this->versionTransition($cache, $lock, $clientForWebhooks, $version, self::VERSION_DEVELOPER_REJECTED);
            }
        }

        $this->respond($response, [
            'data' => $this->versionSubmissionResource($this->versionSubmission($cache, $reviewSubmissionId) ?? $submission),
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
