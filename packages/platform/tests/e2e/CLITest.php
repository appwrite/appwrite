<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\CLI\Adapters\Generic;
use Utopia\CLI\CLI;
use Utopia\Platform\Service;

final class CLITest extends TestCase
{
    public function setUp(): void {}

    public function tearDown(): void {}

    public function testCLISetup(): void
    {
        ob_start();

        $cli = new CLI(new Generic(), ['test.php', 'build', '--email=me@example.com', '--list=item1', '--list=item2']); // Mock command request

        $platform = new TestPlatform();
        $platform->setCli($cli);
        $platform->init(Service::TYPE_TASK);

        $cli = $platform->getCli();
        $cli->run();

        $result = ob_get_clean();

        $this->assertSame('me@example.com-item1-item2', $result);
        $this->assertCount(2, $cli->getTasks());
    }
}
