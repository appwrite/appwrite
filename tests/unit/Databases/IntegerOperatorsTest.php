<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\Engine;
use Appwrite\Databases\IntegerOperators;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Operator;
use Utopia\Database\OperatorType;
use Utopia\Database\Validator\BigInt;

/**
 * Every expectation is what main (appwrite on database 7.4.1) stored for the same operator on an INT column, read
 * back from MariaDB 10.11, PostgreSQL (appwrite/postgres 0.1.0) and MongoDB 8.2.5; null where main's engine refused it.
 */
final class IntegerOperatorsTest extends TestCase
{
    /**
     * @return iterable<string, array{Engine, int, OperatorType, list<int|float>, int|null}>
     */
    public static function mainResults(): iterable
    {
        yield 'MariaDB: inc 11+1.5' => [Engine::MariaDB, 11, OperatorType::Increment, [1.5], 12];
        yield 'MariaDB: inc 10+1.5' => [Engine::MariaDB, 10, OperatorType::Increment, [1.5], 12];
        yield 'MariaDB: inc 10+1.25' => [Engine::MariaDB, 10, OperatorType::Increment, [1.25], 11];
        yield 'MariaDB: inc 10+1.75' => [Engine::MariaDB, 10, OperatorType::Increment, [1.75], 12];
        yield 'MariaDB: inc -11+0.5' => [Engine::MariaDB, -11, OperatorType::Increment, [0.5], -10];
        yield 'MariaDB: inc -12+0.5' => [Engine::MariaDB, -12, OperatorType::Increment, [0.5], -12];
        yield 'MariaDB: dec 11-0.5' => [Engine::MariaDB, 11, OperatorType::Decrement, [0.5], 10];
        yield 'MariaDB: dec 12-0.5' => [Engine::MariaDB, 12, OperatorType::Decrement, [0.5], 12];
        yield 'MariaDB: dec -10-0.5' => [Engine::MariaDB, -10, OperatorType::Decrement, [0.5], -10];
        yield 'MariaDB: inc 10+2 max13.9' => [Engine::MariaDB, 10, OperatorType::Increment, [2, 13.9], 12];
        yield 'MariaDB: inc 12+2 max13.9' => [Engine::MariaDB, 12, OperatorType::Increment, [2, 13.9], 12];
        yield 'MariaDB: inc 12+1.5 max13.9' => [Engine::MariaDB, 12, OperatorType::Increment, [1.5, 13.9], 14];
        yield 'MariaDB: inc 12+1.5 max13.5' => [Engine::MariaDB, 12, OperatorType::Increment, [1.5, 13.5], 14];
        yield 'MariaDB: dec 12-1 min11.1' => [Engine::MariaDB, 12, OperatorType::Decrement, [1, 11.1], 12];
        yield 'MariaDB: dec 13-1 min11.1' => [Engine::MariaDB, 13, OperatorType::Decrement, [1, 11.1], 12];
        yield 'MariaDB: dec 12-0.5 min11.5' => [Engine::MariaDB, 12, OperatorType::Decrement, [0.5, 11.5], 12];
        yield 'MariaDB: mul 11*1.5' => [Engine::MariaDB, 11, OperatorType::Multiply, [1.5], 16];
        yield 'MariaDB: mul 5*2.5' => [Engine::MariaDB, 5, OperatorType::Multiply, [2.5], 12];
        yield 'MariaDB: mul 7*1.5' => [Engine::MariaDB, 7, OperatorType::Multiply, [1.5], 10];
        yield 'MariaDB: mul 7*-1.5' => [Engine::MariaDB, 7, OperatorType::Multiply, [-1.5], -10];
        yield 'MariaDB: mul 3*1.25' => [Engine::MariaDB, 3, OperatorType::Multiply, [1.25], 4];
        yield 'MariaDB: mul 12*2 max25.5' => [Engine::MariaDB, 12, OperatorType::Multiply, [2, 25.5], 24];
        yield 'MariaDB: mul 13*2 max25.5' => [Engine::MariaDB, 13, OperatorType::Multiply, [2, 25.5], 13];
        yield 'MariaDB: mul 10*1.5 max15' => [Engine::MariaDB, 10, OperatorType::Multiply, [1.5, 15], 15];
        yield 'MariaDB: div 10/1.5' => [Engine::MariaDB, 10, OperatorType::Divide, [1.5], 7];
        yield 'MariaDB: div 9/2.5' => [Engine::MariaDB, 9, OperatorType::Divide, [2.5], 4];
        yield 'MariaDB: div 10/2 min5.5' => [Engine::MariaDB, 10, OperatorType::Divide, [2, 5.5], 10];
        yield 'MariaDB: div 10/0.5' => [Engine::MariaDB, 10, OperatorType::Divide, [0.5], 20];
        yield 'MariaDB: mod 10%2.75' => [Engine::MariaDB, 10, OperatorType::Modulo, [2.75], 2];
        yield 'MariaDB: mod 11%4.5' => [Engine::MariaDB, 11, OperatorType::Modulo, [4.5], 2];
        yield 'MariaDB: pow 16^0.5' => [Engine::MariaDB, 16, OperatorType::Power, [0.5], 4];
        yield 'MariaDB: pow 10^0.5' => [Engine::MariaDB, 10, OperatorType::Power, [0.5], 3];
        yield 'MariaDB: pow 2^2.5' => [Engine::MariaDB, 2, OperatorType::Power, [2.5], 6];
        yield 'MariaDB: pow 4^1.5' => [Engine::MariaDB, 4, OperatorType::Power, [1.5], 8];
        yield 'MariaDB: pow 3^2 max8.5' => [Engine::MariaDB, 3, OperatorType::Power, [2, 8.5], 3];
        yield 'MariaDB: pow 2^3 max8.5' => [Engine::MariaDB, 2, OperatorType::Power, [3, 8.5], 8];
        yield 'MariaDB: inc 11+1.5 max20' => [Engine::MariaDB, 11, OperatorType::Increment, [1.5, 20], 12];
        yield 'MariaDB: inc 10+1.5 max20' => [Engine::MariaDB, 10, OperatorType::Increment, [1.5, 20], 12];
        yield 'MariaDB: inc -12+0.5 max20' => [Engine::MariaDB, -12, OperatorType::Increment, [0.5, 20], -12];
        yield 'MariaDB: inc 10+1.25 max20' => [Engine::MariaDB, 10, OperatorType::Increment, [1.25, 20], 11];
        yield 'MariaDB: dec 11-0.5 min0' => [Engine::MariaDB, 11, OperatorType::Decrement, [0.5, 0], 10];
        yield 'MariaDB: dec 12-0.5 min0' => [Engine::MariaDB, 12, OperatorType::Decrement, [0.5, 0], 12];
        yield 'MariaDB: dec -10-0.5 min-100' => [Engine::MariaDB, -10, OperatorType::Decrement, [0.5, -100], -10];
        yield 'MariaDB: mul 5*2.5 max100' => [Engine::MariaDB, 5, OperatorType::Multiply, [2.5, 100], 12];
        yield 'MariaDB: mul 11*1.5 max100' => [Engine::MariaDB, 11, OperatorType::Multiply, [1.5, 100], 16];
        yield 'MariaDB: mul 7*-1.5 max100' => [Engine::MariaDB, 7, OperatorType::Multiply, [-1.5, 100], -10];
        yield 'MariaDB: mul 3*1.25 max100' => [Engine::MariaDB, 3, OperatorType::Multiply, [1.25, 100], 4];
        yield 'MariaDB: div 9/2.5 min0' => [Engine::MariaDB, 9, OperatorType::Divide, [2.5, 0], 4];
        yield 'MariaDB: div 10/1.5 min0' => [Engine::MariaDB, 10, OperatorType::Divide, [1.5, 0], 7];
        yield 'MariaDB: div 5/0.4 min0' => [Engine::MariaDB, 5, OperatorType::Divide, [0.4, 0], 12];
        yield 'MariaDB: pow 2^2.5 max100' => [Engine::MariaDB, 2, OperatorType::Power, [2.5, 100], 6];
        yield 'MariaDB: pow 10^0.5 max100' => [Engine::MariaDB, 10, OperatorType::Power, [0.5, 100], 3];
        yield 'MariaDB: mod -10%2.75' => [Engine::MariaDB, -10, OperatorType::Modulo, [2.75], -2];
        yield 'MariaDB: mod 10%2.5' => [Engine::MariaDB, 10, OperatorType::Modulo, [2.5], 0];
        yield 'MariaDB: mod 11%2.5' => [Engine::MariaDB, 11, OperatorType::Modulo, [2.5], 1];
        yield 'MariaDB: mod 7%-2.5' => [Engine::MariaDB, 7, OperatorType::Modulo, [-2.5], 2];
        yield 'MariaDB: pow 4^-0.5' => [Engine::MariaDB, 4, OperatorType::Power, [-0.5], 0];
        yield 'MariaDB: pow 4^-0.5 max100' => [Engine::MariaDB, 4, OperatorType::Power, [-0.5, 100], 0];
        yield 'MariaDB: pow 16^-0.25' => [Engine::MariaDB, 16, OperatorType::Power, [-0.25], 0];
        yield 'MariaDB: div 5/2 min0.5' => [Engine::MariaDB, 5, OperatorType::Divide, [2, 0.5], 3];
        yield 'MariaDB: div 7/2 min0.5' => [Engine::MariaDB, 7, OperatorType::Divide, [2, 0.5], 4];
        yield 'MariaDB: div -5/2 min-10.5' => [Engine::MariaDB, -5, OperatorType::Divide, [2, -10.5], -3];
        yield 'MariaDB: div 5/2 min2.6' => [Engine::MariaDB, 5, OperatorType::Divide, [2, 2.6], 5];
        yield 'MariaDB: mod 11%3.5' => [Engine::MariaDB, 11, OperatorType::Modulo, [3.5], 0];
        yield 'MariaDB: mod 13%3.5' => [Engine::MariaDB, 13, OperatorType::Modulo, [3.5], 2];
        yield 'MariaDB: mod -11%3.5' => [Engine::MariaDB, -11, OperatorType::Modulo, [3.5], 0];
        yield 'MariaDB: inc 10+2 max10.5' => [Engine::MariaDB, 10, OperatorType::Increment, [2, 10.5], 10];
        yield 'MariaDB: mul 5*3 max15.5' => [Engine::MariaDB, 5, OperatorType::Multiply, [3, 15.5], 15];
        yield 'PostgreSQL: inc 11+1.5' => [Engine::PostgreSQL, 11, OperatorType::Increment, [1.5], null];
        yield 'PostgreSQL: inc 10+1.5' => [Engine::PostgreSQL, 10, OperatorType::Increment, [1.5], null];
        yield 'PostgreSQL: inc 10+1.25' => [Engine::PostgreSQL, 10, OperatorType::Increment, [1.25], null];
        yield 'PostgreSQL: inc 10+1.75' => [Engine::PostgreSQL, 10, OperatorType::Increment, [1.75], null];
        yield 'PostgreSQL: inc -11+0.5' => [Engine::PostgreSQL, -11, OperatorType::Increment, [0.5], null];
        yield 'PostgreSQL: inc -12+0.5' => [Engine::PostgreSQL, -12, OperatorType::Increment, [0.5], null];
        yield 'PostgreSQL: dec 11-0.5' => [Engine::PostgreSQL, 11, OperatorType::Decrement, [0.5], null];
        yield 'PostgreSQL: dec 12-0.5' => [Engine::PostgreSQL, 12, OperatorType::Decrement, [0.5], null];
        yield 'PostgreSQL: dec -10-0.5' => [Engine::PostgreSQL, -10, OperatorType::Decrement, [0.5], null];
        yield 'PostgreSQL: inc 10+2 max13.9' => [Engine::PostgreSQL, 10, OperatorType::Increment, [2, 13.9], 12];
        yield 'PostgreSQL: inc 12+2 max13.9' => [Engine::PostgreSQL, 12, OperatorType::Increment, [2, 13.9], 12];
        yield 'PostgreSQL: inc 12+1.5 max13.9' => [Engine::PostgreSQL, 12, OperatorType::Increment, [1.5, 13.9], 14];
        yield 'PostgreSQL: inc 12+1.5 max13.5' => [Engine::PostgreSQL, 12, OperatorType::Increment, [1.5, 13.5], 14];
        yield 'PostgreSQL: dec 12-1 min11.1' => [Engine::PostgreSQL, 12, OperatorType::Decrement, [1, 11.1], 12];
        yield 'PostgreSQL: dec 13-1 min11.1' => [Engine::PostgreSQL, 13, OperatorType::Decrement, [1, 11.1], 12];
        yield 'PostgreSQL: dec 12-0.5 min11.5' => [Engine::PostgreSQL, 12, OperatorType::Decrement, [0.5, 11.5], 12];
        yield 'PostgreSQL: mul 11*1.5' => [Engine::PostgreSQL, 11, OperatorType::Multiply, [1.5], null];
        yield 'PostgreSQL: mul 5*2.5' => [Engine::PostgreSQL, 5, OperatorType::Multiply, [2.5], null];
        yield 'PostgreSQL: mul 7*1.5' => [Engine::PostgreSQL, 7, OperatorType::Multiply, [1.5], null];
        yield 'PostgreSQL: mul 7*-1.5' => [Engine::PostgreSQL, 7, OperatorType::Multiply, [-1.5], null];
        yield 'PostgreSQL: mul 3*1.25' => [Engine::PostgreSQL, 3, OperatorType::Multiply, [1.25], null];
        yield 'PostgreSQL: mul 12*2 max25.5' => [Engine::PostgreSQL, 12, OperatorType::Multiply, [2, 25.5], 24];
        yield 'PostgreSQL: mul 13*2 max25.5' => [Engine::PostgreSQL, 13, OperatorType::Multiply, [2, 25.5], 13];
        yield 'PostgreSQL: mul 10*1.5 max15' => [Engine::PostgreSQL, 10, OperatorType::Multiply, [1.5, 15], 15];
        yield 'PostgreSQL: div 10/1.5' => [Engine::PostgreSQL, 10, OperatorType::Divide, [1.5], null];
        yield 'PostgreSQL: div 9/2.5' => [Engine::PostgreSQL, 9, OperatorType::Divide, [2.5], null];
        yield 'PostgreSQL: div 10/2 min5.5' => [Engine::PostgreSQL, 10, OperatorType::Divide, [2, 5.5], 10];
        yield 'PostgreSQL: div 10/0.5' => [Engine::PostgreSQL, 10, OperatorType::Divide, [0.5], null];
        yield 'PostgreSQL: mod 10%2.75' => [Engine::PostgreSQL, 10, OperatorType::Modulo, [2.75], 2];
        yield 'PostgreSQL: mod 11%4.5' => [Engine::PostgreSQL, 11, OperatorType::Modulo, [4.5], 2];
        yield 'PostgreSQL: pow 16^0.5' => [Engine::PostgreSQL, 16, OperatorType::Power, [0.5], 4];
        yield 'PostgreSQL: pow 10^0.5' => [Engine::PostgreSQL, 10, OperatorType::Power, [0.5], 3];
        yield 'PostgreSQL: pow 2^2.5' => [Engine::PostgreSQL, 2, OperatorType::Power, [2.5], 6];
        yield 'PostgreSQL: pow 4^1.5' => [Engine::PostgreSQL, 4, OperatorType::Power, [1.5], 8];
        yield 'PostgreSQL: pow 3^2 max8.5' => [Engine::PostgreSQL, 3, OperatorType::Power, [2, 8.5], 3];
        yield 'PostgreSQL: pow 2^3 max8.5' => [Engine::PostgreSQL, 2, OperatorType::Power, [3, 8.5], 8];
        yield 'PostgreSQL: inc 11+1.5 max20' => [Engine::PostgreSQL, 11, OperatorType::Increment, [1.5, 20], 13];
        yield 'PostgreSQL: inc 10+1.5 max20' => [Engine::PostgreSQL, 10, OperatorType::Increment, [1.5, 20], 12];
        yield 'PostgreSQL: inc -12+0.5 max20' => [Engine::PostgreSQL, -12, OperatorType::Increment, [0.5, 20], -12];
        yield 'PostgreSQL: inc 10+1.25 max20' => [Engine::PostgreSQL, 10, OperatorType::Increment, [1.25, 20], 11];
        yield 'PostgreSQL: dec 11-0.5 min0' => [Engine::PostgreSQL, 11, OperatorType::Decrement, [0.5, 0], 11];
        yield 'PostgreSQL: dec 12-0.5 min0' => [Engine::PostgreSQL, 12, OperatorType::Decrement, [0.5, 0], 12];
        yield 'PostgreSQL: dec -10-0.5 min-100' => [Engine::PostgreSQL, -10, OperatorType::Decrement, [0.5, -100], -11];
        yield 'PostgreSQL: mul 5*2.5 max100' => [Engine::PostgreSQL, 5, OperatorType::Multiply, [2.5, 100], 13];
        yield 'PostgreSQL: mul 11*1.5 max100' => [Engine::PostgreSQL, 11, OperatorType::Multiply, [1.5, 100], 17];
        yield 'PostgreSQL: mul 7*-1.5 max100' => [Engine::PostgreSQL, 7, OperatorType::Multiply, [-1.5, 100], -11];
        yield 'PostgreSQL: mul 3*1.25 max100' => [Engine::PostgreSQL, 3, OperatorType::Multiply, [1.25, 100], 4];
        yield 'PostgreSQL: div 9/2.5 min0' => [Engine::PostgreSQL, 9, OperatorType::Divide, [2.5, 0], 4];
        yield 'PostgreSQL: div 10/1.5 min0' => [Engine::PostgreSQL, 10, OperatorType::Divide, [1.5, 0], 7];
        yield 'PostgreSQL: div 5/0.4 min0' => [Engine::PostgreSQL, 5, OperatorType::Divide, [0.4, 0], 13];
        yield 'PostgreSQL: pow 2^2.5 max100' => [Engine::PostgreSQL, 2, OperatorType::Power, [2.5, 100], 6];
        yield 'PostgreSQL: pow 10^0.5 max100' => [Engine::PostgreSQL, 10, OperatorType::Power, [0.5, 100], 3];
        yield 'PostgreSQL: mod -10%2.75' => [Engine::PostgreSQL, -10, OperatorType::Modulo, [2.75], -2];
        yield 'PostgreSQL: mod 10%2.5' => [Engine::PostgreSQL, 10, OperatorType::Modulo, [2.5], 0];
        yield 'PostgreSQL: mod 11%2.5' => [Engine::PostgreSQL, 11, OperatorType::Modulo, [2.5], 1];
        yield 'PostgreSQL: mod 7%-2.5' => [Engine::PostgreSQL, 7, OperatorType::Modulo, [-2.5], 2];
        yield 'PostgreSQL: pow 4^-0.5' => [Engine::PostgreSQL, 4, OperatorType::Power, [-0.5], 0];
        yield 'PostgreSQL: pow 4^-0.5 max100' => [Engine::PostgreSQL, 4, OperatorType::Power, [-0.5, 100], 0];
        yield 'PostgreSQL: pow 16^-0.25' => [Engine::PostgreSQL, 16, OperatorType::Power, [-0.25], 0];
        yield 'PostgreSQL: div 5/2 min0.5' => [Engine::PostgreSQL, 5, OperatorType::Divide, [2, 0.5], 3];
        yield 'PostgreSQL: div 7/2 min0.5' => [Engine::PostgreSQL, 7, OperatorType::Divide, [2, 0.5], 4];
        yield 'PostgreSQL: div -5/2 min-10.5' => [Engine::PostgreSQL, -5, OperatorType::Divide, [2, -10.5], -3];
        yield 'PostgreSQL: div 5/2 min2.6' => [Engine::PostgreSQL, 5, OperatorType::Divide, [2, 2.6], 5];
        yield 'PostgreSQL: mod 11%3.5' => [Engine::PostgreSQL, 11, OperatorType::Modulo, [3.5], 1];
        yield 'PostgreSQL: mod 13%3.5' => [Engine::PostgreSQL, 13, OperatorType::Modulo, [3.5], 3];
        yield 'PostgreSQL: mod -11%3.5' => [Engine::PostgreSQL, -11, OperatorType::Modulo, [3.5], -1];
        yield 'PostgreSQL: inc 10+2 max10.5' => [Engine::PostgreSQL, 10, OperatorType::Increment, [2, 10.5], 10];
        yield 'PostgreSQL: mul 5*3 max15.5' => [Engine::PostgreSQL, 5, OperatorType::Multiply, [3, 15.5], 15];
        yield 'MongoDB: inc 11+1.5' => [Engine::MongoDB, 11, OperatorType::Increment, [1.5], 12];
        yield 'MongoDB: inc 10+1.5' => [Engine::MongoDB, 10, OperatorType::Increment, [1.5], 11];
        yield 'MongoDB: inc 10+1.25' => [Engine::MongoDB, 10, OperatorType::Increment, [1.25], 11];
        yield 'MongoDB: inc 10+1.75' => [Engine::MongoDB, 10, OperatorType::Increment, [1.75], 11];
        yield 'MongoDB: inc -11+0.5' => [Engine::MongoDB, -11, OperatorType::Increment, [0.5], -10];
        yield 'MongoDB: inc -12+0.5' => [Engine::MongoDB, -12, OperatorType::Increment, [0.5], -11];
        yield 'MongoDB: dec 11-0.5' => [Engine::MongoDB, 11, OperatorType::Decrement, [0.5], 10];
        yield 'MongoDB: dec 12-0.5' => [Engine::MongoDB, 12, OperatorType::Decrement, [0.5], 11];
        yield 'MongoDB: dec -10-0.5' => [Engine::MongoDB, -10, OperatorType::Decrement, [0.5], -10];
        yield 'MongoDB: inc 10+2 max13.9' => [Engine::MongoDB, 10, OperatorType::Increment, [2, 13.9], 12];
        yield 'MongoDB: inc 12+2 max13.9' => [Engine::MongoDB, 12, OperatorType::Increment, [2, 13.9], 12];
        yield 'MongoDB: inc 12+1.5 max13.9' => [Engine::MongoDB, 12, OperatorType::Increment, [1.5, 13.9], 13];
        yield 'MongoDB: inc 12+1.5 max13.5' => [Engine::MongoDB, 12, OperatorType::Increment, [1.5, 13.5], 13];
        yield 'MongoDB: dec 12-1 min11.1' => [Engine::MongoDB, 12, OperatorType::Decrement, [1, 11.1], 12];
        yield 'MongoDB: dec 13-1 min11.1' => [Engine::MongoDB, 13, OperatorType::Decrement, [1, 11.1], 12];
        yield 'MongoDB: dec 12-0.5 min11.5' => [Engine::MongoDB, 12, OperatorType::Decrement, [0.5, 11.5], 11];
        yield 'MongoDB: mul 11*1.5' => [Engine::MongoDB, 11, OperatorType::Multiply, [1.5], 16];
        yield 'MongoDB: mul 5*2.5' => [Engine::MongoDB, 5, OperatorType::Multiply, [2.5], 12];
        yield 'MongoDB: mul 7*1.5' => [Engine::MongoDB, 7, OperatorType::Multiply, [1.5], 10];
        yield 'MongoDB: mul 7*-1.5' => [Engine::MongoDB, 7, OperatorType::Multiply, [-1.5], -10];
        yield 'MongoDB: mul 3*1.25' => [Engine::MongoDB, 3, OperatorType::Multiply, [1.25], 3];
        yield 'MongoDB: mul 12*2 max25.5' => [Engine::MongoDB, 12, OperatorType::Multiply, [2, 25.5], 24];
        yield 'MongoDB: mul 13*2 max25.5' => [Engine::MongoDB, 13, OperatorType::Multiply, [2, 25.5], 13];
        yield 'MongoDB: mul 10*1.5 max15' => [Engine::MongoDB, 10, OperatorType::Multiply, [1.5, 15], 15];
        yield 'MongoDB: div 10/1.5' => [Engine::MongoDB, 10, OperatorType::Divide, [1.5], 6];
        yield 'MongoDB: div 9/2.5' => [Engine::MongoDB, 9, OperatorType::Divide, [2.5], 3];
        yield 'MongoDB: div 10/2 min5.5' => [Engine::MongoDB, 10, OperatorType::Divide, [2, 5.5], 10];
        yield 'MongoDB: div 10/0.5' => [Engine::MongoDB, 10, OperatorType::Divide, [0.5], 20];
        yield 'MongoDB: mod 10%2.75' => [Engine::MongoDB, 10, OperatorType::Modulo, [2.75], 1];
        yield 'MongoDB: mod 11%4.5' => [Engine::MongoDB, 11, OperatorType::Modulo, [4.5], 2];
        yield 'MongoDB: pow 16^0.5' => [Engine::MongoDB, 16, OperatorType::Power, [0.5], 4];
        yield 'MongoDB: pow 10^0.5' => [Engine::MongoDB, 10, OperatorType::Power, [0.5], 3];
        yield 'MongoDB: pow 2^2.5' => [Engine::MongoDB, 2, OperatorType::Power, [2.5], 5];
        yield 'MongoDB: pow 4^1.5' => [Engine::MongoDB, 4, OperatorType::Power, [1.5], 8];
        yield 'MongoDB: pow 3^2 max8.5' => [Engine::MongoDB, 3, OperatorType::Power, [2, 8.5], 3];
        yield 'MongoDB: pow 2^3 max8.5' => [Engine::MongoDB, 2, OperatorType::Power, [3, 8.5], 8];
        yield 'MongoDB: inc 11+1.5 max20' => [Engine::MongoDB, 11, OperatorType::Increment, [1.5, 20], 12];
        yield 'MongoDB: inc 10+1.5 max20' => [Engine::MongoDB, 10, OperatorType::Increment, [1.5, 20], 11];
        yield 'MongoDB: inc -12+0.5 max20' => [Engine::MongoDB, -12, OperatorType::Increment, [0.5, 20], -11];
        yield 'MongoDB: inc 10+1.25 max20' => [Engine::MongoDB, 10, OperatorType::Increment, [1.25, 20], 11];
        yield 'MongoDB: dec 11-0.5 min0' => [Engine::MongoDB, 11, OperatorType::Decrement, [0.5, 0], 10];
        yield 'MongoDB: dec 12-0.5 min0' => [Engine::MongoDB, 12, OperatorType::Decrement, [0.5, 0], 11];
        yield 'MongoDB: dec -10-0.5 min-100' => [Engine::MongoDB, -10, OperatorType::Decrement, [0.5, -100], -10];
        yield 'MongoDB: mul 5*2.5 max100' => [Engine::MongoDB, 5, OperatorType::Multiply, [2.5, 100], 12];
        yield 'MongoDB: mul 11*1.5 max100' => [Engine::MongoDB, 11, OperatorType::Multiply, [1.5, 100], 16];
        yield 'MongoDB: mul 7*-1.5 max100' => [Engine::MongoDB, 7, OperatorType::Multiply, [-1.5, 100], -10];
        yield 'MongoDB: mul 3*1.25 max100' => [Engine::MongoDB, 3, OperatorType::Multiply, [1.25, 100], 3];
        yield 'MongoDB: div 9/2.5 min0' => [Engine::MongoDB, 9, OperatorType::Divide, [2.5, 0], 3];
        yield 'MongoDB: div 10/1.5 min0' => [Engine::MongoDB, 10, OperatorType::Divide, [1.5, 0], 6];
        yield 'MongoDB: div 5/0.4 min0' => [Engine::MongoDB, 5, OperatorType::Divide, [0.4, 0], 12];
        yield 'MongoDB: pow 2^2.5 max100' => [Engine::MongoDB, 2, OperatorType::Power, [2.5, 100], 5];
        yield 'MongoDB: pow 10^0.5 max100' => [Engine::MongoDB, 10, OperatorType::Power, [0.5, 100], 3];
        yield 'MongoDB: mod -10%2.75' => [Engine::MongoDB, -10, OperatorType::Modulo, [2.75], -1];
        yield 'MongoDB: mod 10%2.5' => [Engine::MongoDB, 10, OperatorType::Modulo, [2.5], 0];
        yield 'MongoDB: mod 11%2.5' => [Engine::MongoDB, 11, OperatorType::Modulo, [2.5], 1];
        yield 'MongoDB: mod 7%-2.5' => [Engine::MongoDB, 7, OperatorType::Modulo, [-2.5], 2];
        yield 'MongoDB: pow 4^-0.5' => [Engine::MongoDB, 4, OperatorType::Power, [-0.5], 0];
        yield 'MongoDB: pow 4^-0.5 max100' => [Engine::MongoDB, 4, OperatorType::Power, [-0.5, 100], 0];
        yield 'MongoDB: pow 16^-0.25' => [Engine::MongoDB, 16, OperatorType::Power, [-0.25], 0];
        yield 'MongoDB: div 5/2 min0.5' => [Engine::MongoDB, 5, OperatorType::Divide, [2, 0.5], 2];
        yield 'MongoDB: div 7/2 min0.5' => [Engine::MongoDB, 7, OperatorType::Divide, [2, 0.5], 3];
        yield 'MongoDB: div -5/2 min-10.5' => [Engine::MongoDB, -5, OperatorType::Divide, [2, -10.5], -2];
        yield 'MongoDB: div 5/2 min2.6' => [Engine::MongoDB, 5, OperatorType::Divide, [2, 2.6], 5];
        yield 'MongoDB: mod 11%3.5' => [Engine::MongoDB, 11, OperatorType::Modulo, [3.5], 0];
        yield 'MongoDB: mod 13%3.5' => [Engine::MongoDB, 13, OperatorType::Modulo, [3.5], 2];
        yield 'MongoDB: mod -11%3.5' => [Engine::MongoDB, -11, OperatorType::Modulo, [3.5], 0];
        yield 'MongoDB: inc 10+2 max10.5' => [Engine::MongoDB, 10, OperatorType::Increment, [2, 10.5], 10];
        yield 'MongoDB: mul 5*3 max15.5' => [Engine::MongoDB, 5, OperatorType::Multiply, [3, 15.5], 15];
    }

    /**
     * @param list<int|float> $values
     */
    #[DataProvider('mainResults')]
    public function testAFractionalOperandStoresWhatMainStored(Engine $engine, int $stored, OperatorType $method, array $values, ?int $expected): void
    {
        $operator = new Operator($method, 'count', $values);
        $this->assertTrue(IntegerOperators::fractional($operator));

        $resolved = (new IntegerOperators($engine))->resolve($operator, $stored);

        if ($expected === null) {
            $this->assertSame($operator, $resolved, 'Main refused it, so the library must see it unchanged');

            return;
        }

        $change = $resolved->getValue();
        $this->assertContains($resolved->getMethod(), [OperatorType::Increment, OperatorType::Decrement]);
        $this->assertCount(1, $resolved->getValues(), 'A resolved change carries no limit');
        $this->assertTrue(\is_int($change) || BigInt::isIntegerString((string) $change));
        $this->assertSame($expected, $resolved->getMethod() === OperatorType::Increment ? $stored + $change : $stored - $change);
    }

    public function testAWholeOperandAndLimitNeedNoStoredValue(): void
    {
        $this->assertFalse(IntegerOperators::fractional(Operator::increment(2, 10)));
        $this->assertFalse(IntegerOperators::fractional(Operator::multiply(2.0)));
        $this->assertFalse(IntegerOperators::fractional(Operator::arrayAppend([1.5])));
        $this->assertTrue(IntegerOperators::fractional(Operator::increment(2, 10.5)));
        $this->assertTrue(IntegerOperators::fractional(Operator::divide('2.5')));
    }

    public function testAFractionalChangeBeyondFloatPrecisionStaysExact(): void
    {
        $resolved = (new IntegerOperators(Engine::MariaDB))->resolve(Operator::increment(1.5), 9007199254740993);

        $this->assertSame(1, $resolved->getValue(), '9007199254740993 + 1.5 rounds half to even to 9007199254740994');
    }

    /**
     * @return iterable<string, array{Operator, int, int}>
     */
    public static function numericOnPostgreSQLBeyondFloatPrecision(): iterable
    {
        yield 'multiply' => [Operator::multiply(1.5, 99999999999999999), 9007199254740993, 13510798882111490];
        yield 'divide' => [Operator::divide(0.5, 0), 9007199254740993, 18014398509481986];
        yield 'modulo' => [Operator::modulo(2.5), 9007199254740993, 1];
    }

    #[DataProvider('numericOnPostgreSQLBeyondFloatPrecision')]
    public function testPostgreSQLComputesFractionalFactorsExactlyAsNumericDid(Operator $operator, int $stored, int $expected): void
    {
        $resolved = (new IntegerOperators(Engine::PostgreSQL))->resolve($operator, $stored);

        $this->assertSame($expected, $resolved->getMethod() === OperatorType::Increment ? $stored + $resolved->getValue() : $stored - $resolved->getValue());
    }

    public function testAnOperandTooLongForAnIntegerIsLeftForTheLibraryToRefuse(): void
    {
        $operators = new IntegerOperators(Engine::PostgreSQL);

        foreach ([Operator::multiply('1e1000000000', 1.5), new Operator(OperatorType::Divide, 'count', ['1e-1000000000', 0.5]), Operator::multiply('1e99999999999999999999', 1.5)] as $operator) {
            $this->assertSame($operator, $operators->resolve($operator, 10));
        }
    }

    /**
     * @return iterable<string, array{Operator, int}>
     */
    public static function oversizedLimits(): iterable
    {
        yield 'a huge maximum holds nothing back' => [Operator::multiply(1.5, '1e1000000000'), 15];
        yield 'a huge negative maximum holds everything back' => [Operator::multiply(1.5, '-1e1000000000'), 10];
        yield 'a tiny positive maximum holds back a positive result' => [Operator::multiply(1.5, '1e-1000000000'), 10];
        yield 'a huge negative minimum holds nothing back' => [Operator::divide(0.5, '-1e1000000000'), 20];
        yield 'a tiny negative minimum lets a positive result through' => [Operator::divide(0.5, '-1e-1000000000'), 20];
        yield 'a maximum with many fractional digits just above one holds back 15' => [Operator::multiply(1.5, '1.' . \str_repeat('0', 80) . '1'), 10];
        yield 'a maximum with many fractional digits just below sixteen lets 15 through' => [Operator::multiply(1.5, '15.' . \str_repeat('9', 80)), 15];
        yield 'a tiny factor leaves zero' => [Operator::multiply('1e-1000000000', 100), 0];
    }

    #[DataProvider('oversizedLimits')]
    public function testALimitTooLongToWriteOutStillBoundsTheResult(Operator $operator, int $expected): void
    {
        $resolved = (new IntegerOperators(Engine::PostgreSQL))->resolve($operator, 10);

        $this->assertSame($expected, $resolved->getMethod() === OperatorType::Increment ? 10 + $resolved->getValue() : 10 - $resolved->getValue());
    }

    public function testFractionalArrayItemsAreCutTowardZero(): void
    {
        $this->assertSame([1, -1, -2, 2, 0, 7], IntegerOperators::wholeItems(Operator::arrayAppend([1.5, -1.5, -2.5, 2.5, 0.9999, 7]))->getValues());
        $this->assertSame([0, 3], IntegerOperators::wholeItems(Operator::arrayPrepend([0.5, 3.7]))->getValues());
        $this->assertSame([1, 3], IntegerOperators::wholeItems(Operator::arrayInsert(1, 3.5))->getValues());
        $this->assertSame(['a', 2], IntegerOperators::wholeItems(Operator::arrayAppend(['a', 2]))->getValues());
    }
}
