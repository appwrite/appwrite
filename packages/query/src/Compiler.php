<?php

namespace Utopia\Query;

interface Compiler
{
    /**
     * Compile a filter query (equal, greaterThan, contains, between, spatial, vector, logical, etc.)
     */
    public function compileFilter(Query $query): string;

    /**
     * Compile an order query (orderAsc, orderDesc, orderRandom)
     */
    public function compileOrder(Query $query): string;

    /**
     * Compile a limit query
     */
    public function compileLimit(Query $query): string;

    /**
     * Compile an offset query
     */
    public function compileOffset(Query $query): string;

    /**
     * Compile a select query
     */
    public function compileSelect(Query $query): string;

    /**
     * Compile a cursor query (cursorAfter, cursorBefore)
     */
    public function compileCursor(Query $query): string;

    /**
     * Compile an aggregate query (count, sum, avg, min, max)
     */
    public function compileAggregate(Query $query): string;

    /**
     * Compile a group by query
     */
    public function compileGroupBy(Query $query): string;

    /**
     * Compile a join query (join, leftJoin, rightJoin, crossJoin)
     */
    public function compileJoin(Query $query): string;
}
