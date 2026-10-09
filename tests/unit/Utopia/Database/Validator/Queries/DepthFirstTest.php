<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\DepthFirst;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Appwrite\Utopia\Database\Validator\Queries\Users;
use Appwrite\Utopia\Database\Validator\Queries\VcsRepositories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Validator;

final class DepthFirstTest extends TestCase
{
    /**
     * Main's message for each refused query, from database 7.4.1.
     *
     * @return \Iterator<string, array{\Closure(): Validator, string, string}>
     */
    public static function refusals(): \Iterator
    {
        $users = static fn (): Validator => new Users();
        $paging = static fn (): Validator => new DepthFirst([new Limit(), new Offset()]);
        $namespaces = static fn (): Validator => new VcsRepositories();

        yield 'a nested attribute before the and arity' => [$users, '{"method":"and","values":[{"method":"equal","attribute":"missing","values":["x"]}]}', 'Invalid query: Attribute not found in schema: missing'];
        yield 'a nested attribute before the or filters' => [$users, '{"method":"or","values":[{"method":"equal","attribute":"missing","values":["x"]},{"method":"select","values":["name"]}]}', 'Invalid query: Attribute not found in schema: missing'];
        yield 'a nested attribute before elemMatch support' => [$users, '{"method":"elemMatch","attribute":"labels","values":[{"method":"equal","attribute":"x","values":["x"]}]}', 'Invalid query: Attribute not found in schema: x'];
        yield 'a nested method a route does not validate' => [$paging, '{"method":"and","values":[{"method":"equal","attribute":"name","values":["x"]},{"method":"equal","attribute":"name","values":["y"]}]}', 'Invalid query method: equal'];
        yield 'a nested namespace before the method' => [$namespaces, '{"method":"or","values":[{"method":"equal","attribute":"name","values":["x"]},{"method":"equal","attribute":"name","values":["y"]}]}', 'Invalid query: Only namespace can be queried'];
        yield 'an aggregate main did not know' => [$users, '{"method":"count","attribute":"name","values":[]}', 'Invalid query: Invalid query method: count'];
        yield 'a join main did not know' => [$users, '{"method":"join","attribute":"other","values":[]}', 'Invalid query: Invalid query method: join'];
        yield 'a refused regex main did not know' => [$users, '{"method":"regex","attribute":"labels","values":["^a"]}', 'Invalid query: Invalid query method: regex'];
    }

    /**
     * @param \Closure(): Validator $validator
     */
    #[DataProvider('refusals')]
    public function testARefusedQueryKeepsMainsMessage(\Closure $validator, string $query, string $message): void
    {
        $validator = $validator();

        $this->assertFalse($validator->isValid([$query]));
        $this->assertSame($message, $validator->getDescription());
    }

    public function testAQueryMainDidNotKnowStaysAcceptedWhereTheLibraryAcceptsIt(): void
    {
        $validator = new Users();

        $this->assertTrue($validator->isValid(['{"method":"regex","attribute":"name","values":["^a"]}']), $validator->getDescription());
    }

    public function testTheTenantCanBeSelectedAsOnMain(): void
    {
        $validator = new Projects();

        $this->assertTrue($validator->isValid(['{"method":"select","values":["$tenant"]}']), $validator->getDescription());
    }
}
