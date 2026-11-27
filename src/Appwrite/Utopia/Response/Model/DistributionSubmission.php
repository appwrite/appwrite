<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Provider;
use Appwrite\Distribution\Submission\Status;
use Appwrite\Distribution\Submission\Step;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class DistributionSubmission extends Model
{
    private const array EXTERNAL = [
        'editId',
        'versionCode',
        'appId',
        'buildUploadId',
        'buildId',
        'versionId',
        'reviewSubmissionId',
        'betaReviewSubmissionId',
        'phasedReleaseId',
    ];

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Submission ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Submission creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Submission update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('appId', [
                'type' => self::TYPE_STRING,
                'description' => 'App ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('releaseId', [
                'type' => self::TYPE_STRING,
                'description' => 'Release ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('targetId', [
                'type' => self::TYPE_STRING,
                'description' => 'Target ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('artifactId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the submitted artifact.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('platform', [
                'type' => self::TYPE_ENUM,
                'description' => 'Platform of the submitted artifact.',
                'default' => Platform::Android->value,
                'example' => Platform::Android->value,
                'enum' => Platform::values(),
                'enumSDKName' => 'DistributionPlatform',
            ])
            ->addRule('provider', [
                'type' => self::TYPE_ENUM,
                'description' => 'Store the artifact is submitted to.',
                'default' => Provider::Apple->value,
                'example' => Provider::Google->value,
                'enum' => Provider::values(),
                'enumSDKName' => 'DistributionProvider',
            ])
            ->addRule('track', [
                'type' => self::TYPE_STRING,
                'description' => 'Store track. Google: `internal`, `alpha`, `beta`, `production` or a custom track. Apple: `testflight`, `testflight-external` or `production`.',
                'default' => '',
                'example' => 'internal',
            ])
            ->addRule('rollout', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Fraction of users that receive the release, in (0, 1]. 1 is a full rollout.',
                'default' => 1,
                'example' => 0.2,
            ])
            ->addRule('automatic', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the release goes live automatically after approval.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Submission status. The store\'s own state is in `storeStatus`.',
                'default' => Status::Waiting->value,
                'example' => Status::Reviewing->value,
                'enum' => Status::values(),
                'enumSDKName' => 'DistributionSubmissionStatus',
            ])
            ->addRule('storeStatus', [
                'type' => self::TYPE_STRING,
                'description' => 'Raw state reported by the store.',
                'default' => '',
                'example' => 'WAITING_FOR_REVIEW',
            ])
            ->addRule('step', [
                'type' => self::TYPE_STRING,
                'description' => 'Last completed step. Empty before the first, otherwise one of `' . \implode('`, `', Step::values()) . '`.',
                'default' => '',
                'example' => Step::Uploaded->value,
            ])
            ->addRule('external', [
                'type' => self::TYPE_JSON,
                'description' => 'Store identifiers of the submission: the Google edit ID and version code, or the Apple app, build upload, build, version, review submission, beta review submission and phased release IDs.',
                'default' => [],
                'example' => ['editId' => '01234567890123456789'],
            ])
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Idempotency key.',
                'default' => '',
                'example' => 'release-1.2.0-android-internal',
            ])
            ->addRule('attempt', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of attempts made.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('retryAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Earliest date the next attempt may run, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('submittedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the artifact was submitted for review, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('reviewedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the store finished its review, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('publishedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the release was published, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('errorCode', [
                'type' => self::TYPE_STRING,
                'description' => 'Machine-readable failure code. Empty unless the submission failed.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('errorMessage', [
                'type' => self::TYPE_STRING,
                'description' => 'Failure message. Empty unless the submission failed.',
                'default' => '',
                'example' => '',
            ]);
    }

    public function filter(Document $document): Document
    {
        if (!$document->isSet('external')) {
            return $document;
        }

        $external = $document->getAttribute('external');

        return $document->setAttribute('external', \is_array($external) ? \array_intersect_key($external, \array_flip(self::EXTERNAL)) : []);
    }

    public function getName(): string
    {
        return 'DistributionSubmission';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_SUBMISSION;
    }
}
