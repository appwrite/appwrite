<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\MigrationReport;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Database\Document;
use Utopia\Migration\Resource;

final class MigrationReportTest extends TestCase
{
    protected Response $response;

    public function setUp(): void
    {
        $this->response = new Response(new SwooleResponse());
        $this->response->setModel(new MigrationReport());
    }

    /**
     * The Appwrite source counts memberships per team while building a report.
     * The count used to be computed and then dropped on the way out, leaving a
     * report that showed teams with nothing behind them.
     */
    public function testReportsMembershipCounts(): void
    {
        $output = $this->response->output(new Document([
            Resource::TYPE_TEAM => 2,
            Resource::TYPE_MEMBERSHIP => 3,
        ]), Response::MODEL_MIGRATION_REPORT);

        $this->assertSame(3, $output[Resource::TYPE_MEMBERSHIP]);
    }

    public function testReportsNoMembershipsAsZero(): void
    {
        $output = $this->response->output(
            new Document([Resource::TYPE_TEAM => 2]),
            Response::MODEL_MIGRATION_REPORT
        );

        $this->assertSame(0, $output[Resource::TYPE_MEMBERSHIP]);
    }
}
