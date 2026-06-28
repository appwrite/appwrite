<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use Appwrite\Onboarding\Onboarding;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;

final class OnboardingTest extends TestCase
{
    protected function setUp(): void
    {
        Config::setParam('onboarding', [
            'functions.createManualDeployment' => true,
            'functions.createCliDeployment' => true,
            'functions.createVcsDeployment' => true,
            'functions.updateFunctionDeployment' => true,
            'sites.createVcsDeployment' => true,
        ]);
    }

    public function testCompletesMultipleStagesInASingleWrite(): void
    {
        $project = new Document(['$id' => 'project1', 'onboarding' => []]);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->once())
            ->method('updateDocument')
            ->with(
                'projects',
                'project1',
                $this->callback(function (Document $document): bool {
                    $onboarding = $document->getAttribute('onboarding');

                    return isset($onboarding['functions.createCliDeployment'], $onboarding['functions.updateFunctionDeployment'])
                        && $onboarding['functions.createCliDeployment']['status'] === ONBOARDING_STATUS_COMPLETED
                        && $onboarding['functions.createCliDeployment']['actorType'] === ACTOR_TYPE_SYSTEM
                        && ! empty($onboarding['functions.createCliDeployment']['at']);
                })
            );

        Onboarding::complete($dbForPlatform, $project, [
            'functions.createCliDeployment',
            'functions.updateFunctionDeployment',
        ], ACTOR_TYPE_SYSTEM);
    }

    public function testIgnoresConsoleProject(): void
    {
        $project = new Document(['$id' => 'console', 'onboarding' => []]);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->never())->method('updateDocument');

        Onboarding::complete($dbForPlatform, $project, ['functions.createCliDeployment'], ACTOR_TYPE_SYSTEM);
    }

    public function testIgnoresUnknownAndAlreadyCompletedMethods(): void
    {
        $project = new Document(['$id' => 'project1', 'onboarding' => [
            'functions.createCliDeployment' => [
                'status' => ONBOARDING_STATUS_COMPLETED,
                'at' => '2026-01-01 00:00:00.000',
                'actorType' => ACTOR_TYPE_USER,
            ],
        ]]);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->never())->method('updateDocument');

        Onboarding::complete($dbForPlatform, $project, ['functions.createCliDeployment', 'unknown.method'], ACTOR_TYPE_SYSTEM);
    }

    public function testDoesNotOverwriteSkippedStage(): void
    {
        $project = new Document(['$id' => 'project1', 'onboarding' => [
            'sites.createVcsDeployment' => [
                'status' => ONBOARDING_STATUS_SKIPPED,
                'at' => '2026-01-01 00:00:00.000',
                'actorType' => ACTOR_TYPE_USER,
            ],
        ]]);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->never())->method('updateDocument');

        Onboarding::complete($dbForPlatform, $project, ['sites.createVcsDeployment'], ACTOR_TYPE_SYSTEM);
    }
}
