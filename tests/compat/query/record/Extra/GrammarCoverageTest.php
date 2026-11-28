<?php

declare(strict_types=1);

namespace Utopia\Query\Tests\Extra;

use PHPUnit\Framework\TestCase;
use Utopia\Query\AST\Grammar;

/**
 * Calls Grammar's checks directly (the ported tests only reach them through
 * the Func and Cast constructors).
 */
final class GrammarCoverageTest extends TestCase
{
    public function testIsIdentifier(): void
    {
        $this->assertTrue(Grammar::isIdentifier('LOWER'));
        $this->assertTrue(Grammar::isIdentifier('_a1'));
        $this->assertFalse(Grammar::isIdentifier(''));
        $this->assertFalse(Grammar::isIdentifier('1a'));
        $this->assertFalse(Grammar::isIdentifier('NOW '));
        $this->assertFalse(Grammar::isIdentifier('NOW() OR 1=1'));
        $this->assertFalse(Grammar::isIdentifier('é'));
    }

    public function testIsType(): void
    {
        $this->assertTrue(Grammar::isType('INT'));
        $this->assertTrue(Grammar::isType('DOUBLE PRECISION'));
        $this->assertTrue(Grammar::isType("DOUBLE\tPRECISION"));
        $this->assertTrue(Grammar::isType('DOUBLE  PRECISION'));
        $this->assertTrue(Grammar::isType('DECIMAL(10, 2)'));
        $this->assertTrue(Grammar::isType('CHARACTER VARYING (255)'));
        $this->assertTrue(Grammar::isType("NUMERIC( 10 ,\n2 )"));
        $this->assertFalse(Grammar::isType(''));
        $this->assertFalse(Grammar::isType(' INT'));
        $this->assertFalse(Grammar::isType('INT '));
        $this->assertFalse(Grammar::isType('()'));
        $this->assertFalse(Grammar::isType('(1)'));
        $this->assertFalse(Grammar::isType('DECIMAL()'));
        $this->assertFalse(Grammar::isType('DECIMAL(1'));
        $this->assertFalse(Grammar::isType('DECIMAL(1))'));
        $this->assertFalse(Grammar::isType('DECIMAL(1)(2)'));
        $this->assertFalse(Grammar::isType('1INT'));
        $this->assertFalse(Grammar::isType('TEXT) OR (1=1'));
    }
}
