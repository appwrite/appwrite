<?php

declare(strict_types=1);

namespace Utopia\Http;

use PHPUnit\Framework\TestCase;
use Swoole\Constant;
use Utopia\Http\Adapter\Swoole\Mode;

final class ModeTest extends TestCase
{
    public function testProcessMode(): void
    {
        $settings = Mode::HYPERLOOP_A->settings();

        $this->assertFalse($settings[Constant::OPTION_ENABLE_COROUTINE]);
        $this->assertSame(3, $settings[Constant::OPTION_DISPATCH_MODE]);

        // Blocking workers need more processes than cores
        $this->assertGreaterThanOrEqual($settings[Constant::OPTION_REACTOR_NUM], $settings[Constant::OPTION_WORKER_NUM]);

        // No coroutine runtime, so hooks would be meaningless here
        $this->assertArrayNotHasKey(Constant::OPTION_HOOK_FLAGS, $settings);
    }

    public function testCoroutineMode(): void
    {
        $settings = Mode::HYPERLOOP_B->settings();

        $this->assertTrue($settings[Constant::OPTION_ENABLE_COROUTINE]);
        $this->assertSame(2, $settings[Constant::OPTION_DISPATCH_MODE]);
        $this->assertTrue($settings[Constant::OPTION_SEND_YIELD]);

        // Hooks make native blocking I/O yield — the point of this mode
        $this->assertSame(SWOOLE_HOOK_ALL, $settings[Constant::OPTION_HOOK_FLAGS]);
    }

    public function testSharedDefaults(): void
    {
        foreach ([Mode::HYPERLOOP_A, Mode::HYPERLOOP_B] as $mode) {
            $settings = $mode->settings();

            $this->assertFalse($settings[Constant::OPTION_HTTP_COMPRESSION]);
            $this->assertGreaterThanOrEqual(1, $settings[Constant::OPTION_WORKER_NUM]);
            $this->assertGreaterThanOrEqual(1, $settings[Constant::OPTION_REACTOR_NUM]);
        }
    }
}
