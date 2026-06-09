<?php

declare(strict_types=1);

namespace Tests\Unit\Advisor;

use Appwrite\Platform\Modules\Advisor\Http\Insights\Get as GetInsight;
use Appwrite\Platform\Modules\Advisor\Http\Insights\XList as ListInsights;
use Appwrite\Platform\Modules\Advisor\Http\Reports\Delete as DeleteReport;
use Appwrite\Platform\Modules\Advisor\Http\Reports\Get as GetReport;
use Appwrite\Platform\Modules\Advisor\Http\Reports\XList as ListReports;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Platform\Action;

final class AuthTest extends TestCase
{
    #[DataProvider('advisorActionsProvider')]
    public function testAdvisorApisOnlySupportAdminAndKeyAuth(Action $action): void
    {
        /** @var Method $method */
        $method = $action->getLabels()['sdk'];

        $this->assertSame([AuthType::ADMIN, AuthType::KEY], $method->getAuth());
    }

    public static function advisorActionsProvider(): \Iterator
    {
        yield 'get report' => [new GetReport()];
        yield 'list reports' => [new ListReports()];
        yield 'delete report' => [new DeleteReport()];
        yield 'get insight' => [new GetInsight()];
        yield 'list insights' => [new ListInsights()];
    }
}
