<?php

namespace Tests\Unit\Platform\Modules\Compute\Validator;

use Appwrite\Platform\Modules\Compute\Specification as SpecificationConstants;
use Appwrite\Platform\Modules\Compute\Validator\Specification;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

class SpecificationTest extends TestCase
{
    private array $specifications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->specifications = Config::getParam('specifications', []);
    }

    public function testGetAllowedSpecificationsNoLimits(): void
    {
        $validator = new Specification(
            plan: [],
            specifications: $this->specifications,
            maxCpus: 0,
            maxMemory: 0
        );

        $allowed = $validator->getAllowedSpecifications();
        $this->assertCount(count($this->specifications), $allowed);
        $this->assertEquals(
            $this->specifications[array_key_last($this->specifications)]['slug'],
            $allowed[array_key_last($allowed)]
        );
    }

    public function testGetAllowedSpecificationsWithMaxCpusAndMemory(): void
    {
        $validator = new Specification(
            plan: [],
            specifications: $this->specifications,
            maxCpus: 2,
            maxMemory: 2048
        );

        $allowed = $validator->getAllowedSpecifications();
        $this->assertCount(4, $allowed);
        $this->assertEquals(
            SpecificationConstants::S_2VCPU_2GB,
            $allowed[array_key_last($allowed)]
        );
    }

    public function testGetAllowedSpecificationsWithPlanLimits(): void
    {
        $plan = [
            'runtimeSpecifications' => [
                SpecificationConstants::S_05VCPU_512MB,
                SpecificationConstants::S_1VCPU_512MB
            ]
        ];
        $validator = new Specification(
            plan: $plan,
            specifications: $this->specifications,
            maxCpus: 0,
            maxMemory: 0
        );

        $allowed = $validator->getAllowedSpecifications();
        $this->assertCount(2, $allowed);
        $this->assertContains(SpecificationConstants::S_05VCPU_512MB, $allowed);
        $this->assertContains(SpecificationConstants::S_1VCPU_512MB, $allowed);
    }
}
