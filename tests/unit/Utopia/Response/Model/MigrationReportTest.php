<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\MigrationReport;
use PHPUnit\Framework\TestCase;
use Utopia\Migration\Resource;

final class MigrationReportTest extends TestCase
{
    /**
     * The Appwrite source counts memberships per team while building a report.
     * Without a rule for them the count is computed and then dropped on the way
     * out, so the wizard shows teams with no memberships behind them.
     */
    public function testReportsMembershipCounts(): void
    {
        $rules = (new MigrationReport())->getRules();

        $this->assertArrayHasKey(Resource::TYPE_MEMBERSHIP, $rules);
    }
}
