<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Compute;

use Appwrite\Platform\Modules\Compute\Specification as SpecificationConstants;
use Appwrite\Platform\Modules\Functions\Http\Specifications\XList as FunctionsListSpecifications;
use Appwrite\Platform\Modules\Sites\Http\Specifications\XList as SitesListSpecifications;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

require_once __DIR__ . '/../../../../../app/init.php';

final class CapturingSpecificationResponse extends Response
{
    public Document $document;
    public string $model = '';

    public function __construct()
    {
    }

    public function dynamic(Document $document, string $model): void
    {
        $this->document = $document;
        $this->model = $model;
    }
}

final class SpecificationsTest extends TestCase
{
    public function testListSpecificationsNoPlan(): void
    {
        $action = new FunctionsListSpecifications();
        $response = new CapturingSpecificationResponse();

        $action->action('runtimes', $response, []);

        $specs = $response->document->getAttribute('specifications');
        $this->assertNotEmpty($specs);

        foreach ($specs as $spec) {
            $this->assertTrue($spec['enabled']);
            $this->assertEquals('', $spec['reason']);
        }
    }

    public function testListSpecificationsWithPlanAndPlansReason(): void
    {
        $action = new FunctionsListSpecifications();
        $response = new CapturingSpecificationResponse();

        $currentPlan = [
            'buildSpecifications' => [
                SpecificationConstants::S_1VCPU_1GB
            ]
        ];

        $allPlans = [
            'starter' => [
                'buildSpecifications' => [
                    SpecificationConstants::S_1VCPU_1GB
                ]
            ],
            'pro' => [
                'buildSpecifications' => [
                    SpecificationConstants::S_1VCPU_1GB,
                    SpecificationConstants::S_2VCPU_2GB
                ]
            ]
        ];

        $action->action('builds', $response, $currentPlan, $allPlans);

        $specs = $response->document->getAttribute('specifications');

        $specMap = [];
        foreach ($specs as $spec) {
            $specMap[$spec['slug']] = $spec;
        }

        // s-1vcpu-1gb is in current plan -> enabled: true, reason: ''
        $this->assertTrue($specMap[SpecificationConstants::S_1VCPU_1GB]['enabled']);
        $this->assertEquals('', $specMap[SpecificationConstants::S_1VCPU_1GB]['reason']);

        // s-2vcpu-2gb is NOT in current plan, but IS in pro plan -> enabled: false, reason: 'plan'
        $this->assertFalse($specMap[SpecificationConstants::S_2VCPU_2GB]['enabled']);
        $this->assertEquals('plan', $specMap[SpecificationConstants::S_2VCPU_2GB]['reason']);

        // s-0.5vcpu-512mb is NOT in current plan and NOT in any plan -> enabled: false, reason: 'unsupported'
        $this->assertFalse($specMap[SpecificationConstants::S_05VCPU_512MB]['enabled']);
        $this->assertEquals('unsupported', $specMap[SpecificationConstants::S_05VCPU_512MB]['reason']);
    }

    public function testSitesListSpecificationsWithPlanAndPlansReason(): void
    {
        $action = new SitesListSpecifications();
        $response = new CapturingSpecificationResponse();

        $currentPlan = [
            'buildSpecifications' => [
                SpecificationConstants::S_1VCPU_1GB
            ]
        ];

        $allPlans = [
            'starter' => [
                'buildSpecifications' => [
                    SpecificationConstants::S_1VCPU_1GB
                ]
            ],
            'pro' => [
                'buildSpecifications' => [
                    SpecificationConstants::S_1VCPU_1GB,
                    SpecificationConstants::S_2VCPU_2GB
                ]
            ]
        ];

        $action->action('builds', $response, $currentPlan, $allPlans);

        $specs = $response->document->getAttribute('specifications');

        $specMap = [];
        foreach ($specs as $spec) {
            $specMap[$spec['slug']] = $spec;
        }

        $this->assertTrue($specMap[SpecificationConstants::S_1VCPU_1GB]['enabled']);
        $this->assertEquals('', $specMap[SpecificationConstants::S_1VCPU_1GB]['reason']);

        $this->assertFalse($specMap[SpecificationConstants::S_2VCPU_2GB]['enabled']);
        $this->assertEquals('plan', $specMap[SpecificationConstants::S_2VCPU_2GB]['reason']);

        $this->assertFalse($specMap[SpecificationConstants::S_05VCPU_512MB]['enabled']);
        $this->assertEquals('unsupported', $specMap[SpecificationConstants::S_05VCPU_512MB]['reason']);
    }
}
