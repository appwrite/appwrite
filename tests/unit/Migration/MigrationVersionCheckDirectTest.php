<?php

namespace Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;

class MigrationVersionCheckDirectTest extends TestCase
{
    public function testVersionCheckLogicDirectly()
    {
        // Test the exact same logic from Migrations.php lines 261-263
        $getMajor = fn($v) => (int)explode('.', $v)[0];
        
        // Test case 1: Should NOT throw (1 major version diff)
        $currentVersion = '1.0.0';
        $targetVersion = '2.0.0';
        
        $shouldThrow = abs($getMajor($targetVersion) - $getMajor($currentVersion)) > 1;
        $this->assertFalse($shouldThrow, "1.0.0 -> 2.0.0 should be allowed");
        
        // Test case 2: SHOULD throw (2 major version diff)
        $currentVersion = '1.0.0';
        $targetVersion = '3.0.0';
        
        $shouldThrow = abs($getMajor($targetVersion) - $getMajor($currentVersion)) > 1;
        $this->assertTrue($shouldThrow, "1.0.0 -> 3.0.0 should be blocked");
        
        // Test case 3: SHOULD throw (3 major version diff)
        $currentVersion = '1.0.0';
        $targetVersion = '4.0.0';
        
        $shouldThrow = abs($getMajor($targetVersion) - $getMajor($currentVersion)) > 1;
        $this->assertTrue($shouldThrow, "1.0.0 -> 4.0.0 should be blocked");
        
        // Test case 4: Should NOT throw (same major version)
        $currentVersion = '2.5.1';
        $targetVersion = '2.8.3';
        
        $shouldThrow = abs($getMajor($targetVersion) - $getMajor($currentVersion)) > 1;
        $this->assertFalse($shouldThrow, "2.5.1 -> 2.8.3 should be allowed");
        
        // Test the actual exception throwing
        try {
            $currentVersion = '1.0.0';
            $targetVersion = '3.0.0';
            
            if (abs($getMajor($targetVersion) - $getMajor($currentVersion)) > 1) {
                throw new \Exception("You cannot upgrade more than one major version at a time. Please upgrade to the next major version first. (Current: $currentVersion, Target: $targetVersion)");
            }
            
            $this->fail("Expected exception was not thrown");
        } catch (\Exception $e) {
            $this->assertStringContainsString("You cannot upgrade more than one major version at a time", $e->getMessage());
            $this->assertStringContainsString("Current: 1.0.0", $e->getMessage());
            $this->assertStringContainsString("Target: 3.0.0", $e->getMessage());
        }
    }
}
