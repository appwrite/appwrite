<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Appwrite\Database\Provisioner;
use Appwrite\Platform\Modules\Organization\Http\Projects\Create as OrganizationCreate;
use Appwrite\Platform\Modules\Projects\Http\Projects\Create as ProjectsCreate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

final class ProvisionerContractTest extends TestCase
{
    /**
     * @return \Iterator<string, array{class-string}>
     */
    public static function actions(): \Iterator
    {
        yield 'organization' => [OrganizationCreate::class];
        yield 'projects' => [ProjectsCreate::class];
    }

    /**
     * A distribution may register a `databaseFactory` resource that is not an
     * Appwrite\Database\Factory, so an action typing the implementation instead
     * of the contract fails at call time with a TypeError, not at build time.
     *
     * @param class-string $action
     */
    #[DataProvider('actions')]
    public function testProjectCreationTypesTheContract(string $action): void
    {
        $parameters = (new ReflectionMethod($action, 'action'))->getParameters();

        foreach ($parameters as $parameter) {
            if ($parameter->getName() !== 'databaseFactory') {
                continue;
            }

            $type = $parameter->getType();

            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertSame(
                Provisioner::class,
                $type->getName(),
                $action . '::action() must type $databaseFactory as the contract',
            );

            return;
        }

        $this->fail($action . '::action() has no $databaseFactory parameter');
    }
}
