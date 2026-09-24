<?php

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Integration\MongoDBClient;

/**
 * Pins the contract of MongoDBClient::objectToArray():
 *
 * - Nested populated stdClass objects are converted to associative arrays.
 * - An empty stdClass — at any nesting depth — is PRESERVED as stdClass.
 *
 * This matters because the MongoDB PHP driver encodes an empty stdClass as an
 * empty BSON document ({}), while an empty PHP array is encoded as an empty
 * BSON array ([]). Builder output relies on this distinction for aggregate
 * pipeline stages like {"$match": {}} (commit 5607a72).
 *
 * Runs under the Query suite so it does not require ext-mongodb / Docker —
 * the method is pure PHP logic and accessed via reflection on an instance
 * created without invoking the constructor.
 */
class MongoDBClientObjectToArrayTest extends TestCase
{
    private MongoDBClient $client;

    /**
     * @var callable(mixed): mixed
     */
    private $convert;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(MongoDBClient::class);
        /** @var MongoDBClient $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $this->client = $instance;

        $method = $reflection->getMethod('objectToArray');
        /** @var callable(mixed): mixed $bound */
        $bound = $method->getClosure($this->client);
        $this->convert = $bound;
    }

    public function testScalarsPassThrough(): void
    {
        $fn = $this->convert;

        $this->assertSame(1, $fn(1));
        $this->assertSame('hello', $fn('hello'));
        $this->assertNull($fn(null));
        $this->assertTrue($fn(true));
        $this->assertSame(1.5, $fn(1.5));
    }

    public function testPopulatedObjectBecomesArray(): void
    {
        $fn = $this->convert;

        $input = new \stdClass();
        $input->name = 'alice';
        $input->age = 30;

        $result = $fn($input);

        $this->assertIsArray($result);
        $this->assertSame(['name' => 'alice', 'age' => 30], $result);
    }

    public function testNestedPopulatedObjectsBecomeArrays(): void
    {
        $fn = $this->convert;

        $inner = new \stdClass();
        $inner->b = 2;

        $outer = new \stdClass();
        $outer->a = 1;
        $outer->nested = $inner;

        $result = $fn($outer);

        $this->assertSame(['a' => 1, 'nested' => ['b' => 2]], $result);
    }

    public function testEmptyObjectAtTopLevelStaysStdClass(): void
    {
        $fn = $this->convert;

        $empty = new \stdClass();
        $result = $fn($empty);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame([], \get_object_vars($result));
    }

    public function testEmptyObjectNestedInPopulatedObjectStaysStdClass(): void
    {
        $fn = $this->convert;

        $empty = new \stdClass();

        $outer = new \stdClass();
        $outer->match = $empty;
        $outer->other = 'scalar';

        $result = $fn($outer);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('match', $result);
        $this->assertInstanceOf(\stdClass::class, $result['match']);
        $this->assertSame([], \get_object_vars($result['match']));
        $this->assertSame('scalar', $result['other']);
    }

    public function testEmptyObjectNestedInArrayStaysStdClass(): void
    {
        $fn = $this->convert;

        $input = ['match' => new \stdClass()];
        $result = $fn($input);

        $this->assertIsArray($result);
        $this->assertInstanceOf(\stdClass::class, $result['match']);
    }

    public function testEmptyObjectInsideListArrayStaysStdClass(): void
    {
        $fn = $this->convert;

        $input = [new \stdClass(), new \stdClass()];
        $result = $fn($input);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(\stdClass::class, $result[0]);
        $this->assertInstanceOf(\stdClass::class, $result[1]);
    }

    public function testMultipleEmptyObjectsAtMixedDepthsAllStayStdClass(): void
    {
        $fn = $this->convert;

        $deep = new \stdClass();
        $deep->empty1 = new \stdClass();
        $deep->list = [new \stdClass(), ['nested' => new \stdClass()]];

        $root = new \stdClass();
        $root->empty2 = new \stdClass();
        $root->deep = $deep;

        $result = $fn($root);

        $this->assertIsArray($result);
        $this->assertInstanceOf(\stdClass::class, $result['empty2']);
        $this->assertIsArray($result['deep']);
        $this->assertInstanceOf(\stdClass::class, $result['deep']['empty1']);
        $this->assertIsArray($result['deep']['list']);
        $this->assertInstanceOf(\stdClass::class, $result['deep']['list'][0]);
        $this->assertIsArray($result['deep']['list'][1]);
        $this->assertInstanceOf(\stdClass::class, $result['deep']['list'][1]['nested']);
    }

    public function testAggregatePipelineShapeIsPreserved(): void
    {
        $fn = $this->convert;

        // Simulates the output of json_decode($json, false) for:
        // {"pipeline":[{"$match":{}},{"$project":{"name":1}}]}
        $matchStage = new \stdClass();
        $matchStage->{'$match'} = new \stdClass();

        $projectInner = new \stdClass();
        $projectInner->name = 1;
        $projectStage = new \stdClass();
        $projectStage->{'$project'} = $projectInner;

        $root = new \stdClass();
        $root->pipeline = [$matchStage, $projectStage];

        $result = $fn($root);

        $this->assertIsArray($result);
        $this->assertIsArray($result['pipeline']);
        $this->assertCount(2, $result['pipeline']);

        // $match: {} — must remain stdClass so BSON encodes as document, not array
        $this->assertIsArray($result['pipeline'][0]);
        $this->assertArrayHasKey('$match', $result['pipeline'][0]);
        $this->assertInstanceOf(\stdClass::class, $result['pipeline'][0]['$match']);

        // $project: {name: 1} — populated, gets converted to array
        $this->assertIsArray($result['pipeline'][1]);
        $this->assertSame(['name' => 1], $result['pipeline'][1]['$project']);
    }
}
