<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\DistributionSubmission;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class DistributionSubmissionTest extends TestCase
{
    public function testPayloadExposesOnlyStoreIdentifiers(): void
    {
        $submission = new Document([
            '$id' => 'submission1',
            'external' => [
                'checkpoint' => ['session' => ['appId' => '1234567890', 'buildUploadFileId' => 'file1', 'parts' => []]],
                'claim' => ['id' => 'claim1', 'expiresAt' => '2046-01-01 00:00:00.000'],
                'control' => ['status' => 'canceled', 'attempt' => 1],
                'committed' => true,
                'expiryTimeSeconds' => '1767225600',
                'editId' => '01234567890123456789',
                'buildId' => 'build1',
            ],
        ]);

        $payload = (new DistributionSubmission())->payload($submission);

        $this->assertSame(['editId' => '01234567890123456789', 'buildId' => 'build1'], $payload['external']);
    }

    public function testPayloadExposesEveryAppleIdentifier(): void
    {
        $identifiers = [
            'appId' => '1234567890',
            'buildUploadId' => 'upload1',
            'buildId' => 'build1',
            'versionId' => 'version1',
            'reviewSubmissionId' => 'review1',
            'betaReviewSubmissionId' => 'betaReview1',
            'phasedReleaseId' => 'phased1',
        ];
        $submission = new Document([
            'external' => [
                ...$identifiers,
                'bundleId' => 'io.appwrite.distribution.ios',
                'version' => '1.0.0',
                'build' => '1',
                'buildUploadFileId' => 'file1',
                'track' => 'testflight',
            ],
        ]);

        $payload = (new DistributionSubmission())->payload($submission);

        $this->assertSame($identifiers, $payload['external']);
    }

    public function testPayloadExposesGoogleIdentifiers(): void
    {
        $submission = new Document([
            'external' => [
                'packageName' => 'io.appwrite.distribution',
                'editId' => '01234567890123456789',
                'versionCode' => 7,
                'changesNotSentForReview' => true,
                'draft' => true,
                'track' => 'qa',
                'session' => 'https://upload.example/session',
            ],
        ]);

        $payload = (new DistributionSubmission())->payload($submission);

        $this->assertSame(['editId' => '01234567890123456789', 'versionCode' => 7], $payload['external']);
    }

    public function testEmptyExternalPassesThrough(): void
    {
        $submission = (new DistributionSubmission())->filter(new Document(['external' => []]));

        $this->assertSame([], $submission->getAttribute('external'));
    }

    public function testNonArrayExternalIsEmptied(): void
    {
        $submission = (new DistributionSubmission())->filter(new Document(['external' => '{"claim":"claim1"}']));

        $this->assertSame([], $submission->getAttribute('external'));
    }

    public function testMissingExternalStaysUnset(): void
    {
        $submission = (new DistributionSubmission())->filter(new Document([]));

        $this->assertFalse($submission->isSet('external'));
    }
}
