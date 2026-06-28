<?php

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
            'functions.createDeployment' => true,
            'functions.updateFunctionDeployment' => true,
            'sites.createDeployment' => true,
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

                    return isset($onboarding['functions.createDeployment'], $onboarding['functions.updateFunctionDeployment'])
                        && $onboarding['functions.createDeployment']['status'] === ONBOARDING_STATUS_COMPLETED
                        && $onboarding['functions.createDeployment']['actorType'] === ACTOR_TYPE_SYSTEM
                        && ! empty($onboarding['functions.createDeployment']['at']);
                })
            );

        Onboarding::complete($dbForPlatform, $project, [
            'functions.createDeployment',
            'functions.updateFunctionDeployment',
        ], ACTOR_TYPE_SYSTEM);
    }

    public function testIgnoresConsoleProject(): void
    {
        $project = new Document(['$id' => 'console', 'onboarding' => []]);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->never())->method('updateDocument');

        Onboarding::complete($dbForPlatform, $project, ['functions.createDeployment'], ACTOR_TYPE_SYSTEM);
    }

    public function testIgnoresUnknownAndAlreadyCompletedMethods(): void
    {
        $project = new Document(['$id' => 'project1', 'onboarding' => [
            'functions.createDeployment' => [
                'status' => ONBOARDING_STATUS_COMPLETED,
                'at' => '2026-01-01 00:00:00.000',
                'actorType' => ACTOR_TYPE_USER,
            ],
        ]]);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->never())->method('updateDocument');

        Onboarding::complete($dbForPlatform, $project, ['functions.createDeployment', 'unknown.method'], ACTOR_TYPE_SYSTEM);
    }

    public function testDoesNotOverwriteSkippedStage(): void
    {
        $project = new Document(['$id' => 'project1', 'onboarding' => [
            'sites.createDeployment' => [
                'status' => ONBOARDING_STATUS_SKIPPED,
                'at' => '2026-01-01 00:00:00.000',
                'actorType' => ACTOR_TYPE_USER,
            ],
        ]]);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->never())->method('updateDocument');

        Onboarding::complete($dbForPlatform, $project, ['sites.createDeployment'], ACTOR_TYPE_SYSTEM);
    }
}
