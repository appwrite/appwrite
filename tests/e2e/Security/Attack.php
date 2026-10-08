<?php

declare(strict_types=1);

namespace Tests\E2E\Security;

interface Attack
{
    public static function getName(): string;

    public function applies(RouteTarget $route): bool;

    /**
     * @return list<Finding>
     */
    public function probe(RouteTarget $route, World $world, Probe $http): array;
}
