<?php

declare(strict_types=1);

/**
 * Utopia PHP Framework
 *
 *
 * @link https://github.com/utopia-php/framework
 *
 * @author Eldad Fux <eldad@appwrite.io>
 *
 * @version 1.0 RC4
 *
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\System\System;

final class SystemTest extends TestCase
{
    public function setUp(): void {}

    public function tearDown(): void {}

    public function testOs(): void
    {
        $this->assertNotEmpty(System::getOS());
        $this->assertNotEmpty(System::getArch());
        $this->assertNotEmpty(System::getArchEnum());
        $this->assertNotEmpty(System::getHostname());
        $this->expectException('Exception');
        System::isArch('throw');
    }

    public function testArchConsistency(): void
    {
        $arch = System::getArchEnum();
        $checks = [
            System::X86 => System::isX86(),
            System::PPC => System::isPPC(),
            System::ARM64 => System::isArm64(),
            System::ARMV7 => System::isArmV7(),
            System::ARMV8 => System::isArmV8(),
        ];

        $this->assertSame([$arch], array_keys(array_filter($checks)));
        $this->assertTrue(System::isArch($arch));
    }

    public function testGetCPUCores(): void
    {
        $this->assertGreaterThan(0, System::getCPUCores());
    }

    public function testGetCPU(): void
    {
        $this->assertGreaterThan(0, System::getCPU());
    }

    public function testGetDiskTotal(): void
    {
        $this->assertGreaterThan(0, System::getDiskTotal());
    }

    public function testGetDiskFree(): void
    {
        $this->assertGreaterThanOrEqual(0, System::getDiskFree());
    }

    // Methods only implemented for Linux
    public function testGetCPUUsage(): void
    {
        if (System::getOS() === 'Linux') {
            $this->assertGreaterThanOrEqual(0, System::getCPUUsage(5));
        } else {
            $this->expectException('Exception');
            System::getCPUUsage(5);
        }
    }

    public function testGetMemoryTotal(): void
    {
        if (\in_array(System::getOS(), ['Linux', 'Darwin'])) {
            $this->assertGreaterThan(0, System::getMemoryTotal());
        } else {
            $this->expectException('Exception');
            System::getMemoryTotal();
        }
    }

    public function testGetMemory(): void
    {
        if (\in_array(System::getOS(), ['Linux', 'Darwin'])) {
            $memory = System::getMemory();
            $this->assertGreaterThanOrEqual(0, $memory);
            $this->assertLessThanOrEqual(System::getMemoryTotal(), $memory);
        } else {
            $this->expectException('Exception');
            System::getMemory();
        }
    }

    public function testGetMemoryFree(): void
    {
        if (\in_array(System::getOS(), ['Linux', 'Darwin'])) {
            $this->assertGreaterThanOrEqual(0, System::getMemoryFree());
        } else {
            $this->expectException('Exception');
            System::getMemoryFree();
        }
    }

    public function testGetMemoryAvailable(): void
    {
        if (System::getOS() === 'Linux') {
            $this->assertGreaterThanOrEqual(0, System::getMemoryAvailable());
        } else {
            $this->expectException('Exception');
            System::getMemoryAvailable();
        }
    }

    public function testGetIOUsage(): void
    {
        if (System::getOS() === 'Linux') {
            $this->assertArrayHasKey('total', System::getIOUsage());
        } else {
            $this->expectException('Exception');
            System::getIOUsage();
        }
    }

    public function testGetNetworkUsage(): void
    {
        if (System::getOS() === 'Linux') {
            $this->assertArrayHasKey('total', System::getNetworkUsage());
        } else {
            $this->expectException('Exception');
            System::getNetworkUsage();
        }
    }

    public function testGetEnv(): void
    {
        $this->assertSame('VALUEA', System::getEnv('TESTA', 'DEFAULTA'));
        $this->assertSame('VALUEB', System::getEnv('TESTB', 'DEFAULTB'));
        $this->assertSame('DEFAULTC', System::getEnv('TESTC', 'DEFAULTC'));
        $this->assertEquals(null, System::getEnv('TESTC'));
    }
}
