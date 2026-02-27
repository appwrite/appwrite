<?php

namespace Tests\Unit\Platform\Modules\Installer\Runtime;

use Appwrite\Platform\Installer\Runtime\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    // --- Constructor ---

    public function testDefaultValues(): void
    {
        $config = new Config();

        $this->assertEquals('80', $config->getDefaultHttpPort());
        $this->assertEquals('443', $config->getDefaultHttpsPort());
        $this->assertEquals('appwrite', $config->getOrganization());
        $this->assertEquals('appwrite', $config->getImage());
        $this->assertFalse($config->getNoStart());
        $this->assertFalse($config->isUpgrade());
        $this->assertFalse($config->isLocal());
        $this->assertNull($config->getHostPath());
        $this->assertNull($config->getLockedDatabase());
        $this->assertEmpty($config->getVars());
    }

    public function testConstructorWithKnownKeys(): void
    {
        $config = new Config([
            'defaultHttpPort' => '8080',
            'isUpgrade' => true,
            'organization' => 'myorg',
        ]);

        $this->assertEquals('8080', $config->getDefaultHttpPort());
        $this->assertTrue($config->isUpgrade());
        $this->assertEquals('myorg', $config->getOrganization());
    }

    public function testConstructorWithUnknownKeysTreatsAsVars(): void
    {
        $vars = [
            '_APP_ENV' => 'production',
            '_APP_DOMAIN' => 'example.com',
        ];
        $config = new Config($vars);

        $this->assertEquals($vars, $config->getVars());
        // Defaults should remain
        $this->assertEquals('80', $config->getDefaultHttpPort());
    }

    // --- apply ---

    public function testApplyAllFields(): void
    {
        $config = new Config();
        $config->apply([
            'defaultHttpPort' => '3000',
            'defaultHttpsPort' => '3443',
            'organization' => 'testorg',
            'image' => 'testimage',
            'noStart' => true,
            'isUpgrade' => true,
            'isLocal' => true,
            'hostPath' => '/home/user',
            'lockedDatabase' => 'mariadb',
            'vars' => [['name' => '_APP_ENV', 'default' => 'production']],
        ]);

        $this->assertEquals('3000', $config->getDefaultHttpPort());
        $this->assertEquals('3443', $config->getDefaultHttpsPort());
        $this->assertEquals('testorg', $config->getOrganization());
        $this->assertEquals('testimage', $config->getImage());
        $this->assertTrue($config->getNoStart());
        $this->assertTrue($config->isUpgrade());
        $this->assertTrue($config->isLocal());
        $this->assertEquals('/home/user', $config->getHostPath());
        $this->assertEquals('mariadb', $config->getLockedDatabase());
        $this->assertCount(1, $config->getVars());
    }

    public function testApplyIgnoresNullAndEmptyStringValues(): void
    {
        $config = new Config(['defaultHttpPort' => '9090']);

        $config->apply(['defaultHttpPort' => '']);
        $this->assertEquals('9090', $config->getDefaultHttpPort());

        $config->apply(['defaultHttpPort' => null]);
        $this->assertEquals('9090', $config->getDefaultHttpPort());
    }

    public function testApplyHostPathCanBeSetToNull(): void
    {
        $config = new Config();
        $config->setHostPath('/some/path');
        $this->assertEquals('/some/path', $config->getHostPath());

        $config->apply(['hostPath' => null]);
        $this->assertNull($config->getHostPath());
    }

    public function testApplyPartialUpdate(): void
    {
        $config = new Config([
            'defaultHttpPort' => '8080',
            'defaultHttpsPort' => '8443',
            'organization' => 'original',
        ]);

        $config->apply(['organization' => 'updated']);

        $this->assertEquals('8080', $config->getDefaultHttpPort());
        $this->assertEquals('8443', $config->getDefaultHttpsPort());
        $this->assertEquals('updated', $config->getOrganization());
    }

    // --- toArray ---

    public function testToArrayRoundTrip(): void
    {
        $config = new Config();
        $config->apply([
            'defaultHttpPort' => '3000',
            'defaultHttpsPort' => '3443',
            'organization' => 'testorg',
            'image' => 'testimage',
            'noStart' => true,
            'isUpgrade' => true,
            'isLocal' => true,
            'hostPath' => '/home/user',
            'lockedDatabase' => 'mongodb',
            'vars' => [['name' => 'KEY', 'default' => 'value']],
        ]);

        $array = $config->toArray();

        $this->assertEquals('3000', $array['defaultHttpPort']);
        $this->assertEquals('3443', $array['defaultHttpsPort']);
        $this->assertEquals('testorg', $array['organization']);
        $this->assertEquals('testimage', $array['image']);
        $this->assertTrue($array['noStart']);
        $this->assertTrue($array['isUpgrade']);
        $this->assertTrue($array['isLocal']);
        $this->assertEquals('/home/user', $array['hostPath']);
        $this->assertEquals('mongodb', $array['lockedDatabase']);
        $this->assertCount(1, $array['vars']);
    }

    public function testToArrayCanRecreateConfig(): void
    {
        $original = new Config([
            'defaultHttpPort' => '5000',
            'isLocal' => true,
            'lockedDatabase' => 'mariadb',
        ]);

        $rebuilt = new Config($original->toArray());

        $this->assertEquals($original->getDefaultHttpPort(), $rebuilt->getDefaultHttpPort());
        $this->assertEquals($original->isLocal(), $rebuilt->isLocal());
        $this->assertEquals($original->getLockedDatabase(), $rebuilt->getLockedDatabase());
        $this->assertEquals($original->toArray(), $rebuilt->toArray());
    }

    // --- Setters and Getters ---

    public function testSetAndGetDefaultHttpPort(): void
    {
        $config = new Config();
        $config->setDefaultHttpPort('9090');
        $this->assertEquals('9090', $config->getDefaultHttpPort());
    }

    public function testSetAndGetDefaultHttpsPort(): void
    {
        $config = new Config();
        $config->setDefaultHttpsPort('9443');
        $this->assertEquals('9443', $config->getDefaultHttpsPort());
    }

    public function testSetAndGetOrganization(): void
    {
        $config = new Config();
        $config->setOrganization('myorg');
        $this->assertEquals('myorg', $config->getOrganization());
    }

    public function testSetAndGetImage(): void
    {
        $config = new Config();
        $config->setImage('myimage');
        $this->assertEquals('myimage', $config->getImage());
    }

    public function testSetAndGetNoStart(): void
    {
        $config = new Config();
        $config->setNoStart(true);
        $this->assertTrue($config->getNoStart());
        $config->setNoStart(false);
        $this->assertFalse($config->getNoStart());
    }

    public function testSetAndGetIsUpgrade(): void
    {
        $config = new Config();
        $config->setIsUpgrade(true);
        $this->assertTrue($config->isUpgrade());
        $config->setIsUpgrade(false);
        $this->assertFalse($config->isUpgrade());
    }

    public function testSetAndGetIsLocal(): void
    {
        $config = new Config();
        $config->setIsLocal(true);
        $this->assertTrue($config->isLocal());
        $config->setIsLocal(false);
        $this->assertFalse($config->isLocal());
    }

    public function testSetAndGetHostPath(): void
    {
        $config = new Config();
        $config->setHostPath('/some/path');
        $this->assertEquals('/some/path', $config->getHostPath());
        $config->setHostPath(null);
        $this->assertNull($config->getHostPath());
    }

    public function testSetAndGetLockedDatabase(): void
    {
        $config = new Config();
        $config->setLockedDatabase('mariadb');
        $this->assertEquals('mariadb', $config->getLockedDatabase());
        $config->setLockedDatabase(null);
        $this->assertNull($config->getLockedDatabase());
    }

    public function testSetAndGetVars(): void
    {
        $config = new Config();
        $vars = [
            ['name' => '_APP_ENV', 'default' => 'production'],
            ['name' => '_APP_DOMAIN', 'default' => 'localhost'],
        ];
        $config->setVars($vars);
        $this->assertEquals($vars, $config->getVars());
    }

    // --- JSON serialization ---

    public function testJsonRoundTrip(): void
    {
        $config = new Config([
            'defaultHttpPort' => '5000',
            'isUpgrade' => true,
            'lockedDatabase' => 'mongodb',
        ]);

        $json = json_encode($config->toArray(), JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        $rebuilt = new Config($decoded);
        $this->assertEquals($config->toArray(), $rebuilt->toArray());
    }

    // --- Constructor edge cases ---

    public function testConstructorWithEmptyArray(): void
    {
        $config = new Config([]);
        // Empty array has no known keys, so it gets set as vars
        // But empty vars is still empty
        $this->assertEmpty($config->getVars());
        $this->assertEquals('80', $config->getDefaultHttpPort());
    }

    public function testConstructorWithMixedKnownAndUnknownKeys(): void
    {
        // If at least one known key is found, apply() is used (not setVars)
        $config = new Config([
            'defaultHttpPort' => '9090',
            'unknownKey' => 'someValue',
        ]);
        // Known key should be applied
        $this->assertEquals('9090', $config->getDefaultHttpPort());
        // Unknown key should be silently ignored by apply()
        // Vars should remain empty since containsKnownKeys returns true
        $this->assertEmpty($config->getVars());
    }

    // --- apply edge cases ---

    public function testApplyWithEmptyArray(): void
    {
        $config = new Config(['defaultHttpPort' => '1234']);
        $config->apply([]);
        // Should not change anything
        $this->assertEquals('1234', $config->getDefaultHttpPort());
    }

    public function testApplyBooleanCastingNoStart(): void
    {
        $config = new Config();

        // Truthy int
        $config->apply(['noStart' => 1]);
        $this->assertTrue($config->getNoStart());

        // Falsy int
        $config->apply(['noStart' => 0]);
        $this->assertFalse($config->getNoStart());
    }

    public function testApplyBooleanCastingIsUpgrade(): void
    {
        $config = new Config();

        $config->apply(['isUpgrade' => 1]);
        $this->assertTrue($config->isUpgrade());

        $config->apply(['isUpgrade' => 0]);
        $this->assertFalse($config->isUpgrade());
    }

    public function testApplyBooleanCastingIsLocal(): void
    {
        $config = new Config();

        $config->apply(['isLocal' => 'true']); // string "true" is truthy
        $this->assertTrue($config->isLocal());

        $config->apply(['isLocal' => '']); // empty string is falsy
        // But wait: the code checks $values['isLocal'] !== null first
        // '' is not null, so (bool)'' = false
        $this->assertFalse($config->isLocal());
    }

    public function testApplyNoStartWithNullDoesNotChange(): void
    {
        $config = new Config();
        $config->setNoStart(true);
        $config->apply(['noStart' => null]);
        // null is excluded by the null check
        $this->assertTrue($config->getNoStart());
    }

    public function testApplyVarsWithNonArrayIgnored(): void
    {
        $config = new Config();
        $config->setVars([['name' => 'KEY', 'default' => 'val']]);

        $config->apply(['vars' => 'not an array']);
        // Should not overwrite
        $this->assertCount(1, $config->getVars());
    }

    public function testApplyVarsWithNullIgnored(): void
    {
        $config = new Config();
        $config->setVars([['name' => 'KEY', 'default' => 'val']]);

        $config->apply(['vars' => null]);
        // is_array(null) = false, so should not overwrite
        $this->assertCount(1, $config->getVars());
    }

    public function testApplyHostPathEmptyStringBecomesNull(): void
    {
        $config = new Config();
        $config->setHostPath('/some/path');

        $config->apply(['hostPath' => '']);
        // Empty string is handled: !== null && !== '' is false, so sets null
        $this->assertNull($config->getHostPath());
    }

    public function testApplyLockedDatabaseIgnoresEmpty(): void
    {
        $config = new Config();
        $config->setLockedDatabase('mariadb');

        $config->apply(['lockedDatabase' => '']);
        // hasValidStringValue returns false for empty string
        $this->assertEquals('mariadb', $config->getLockedDatabase());
    }

    public function testApplyLockedDatabaseIgnoresNull(): void
    {
        $config = new Config();
        $config->setLockedDatabase('mongodb');

        $config->apply(['lockedDatabase' => null]);
        // hasValidStringValue returns false for null
        $this->assertEquals('mongodb', $config->getLockedDatabase());
    }

    public function testApplyPortWithIntegerValue(): void
    {
        $config = new Config();
        $config->apply(['defaultHttpPort' => 3000]);
        // (string)3000 = '3000', not empty, so it should be applied
        $this->assertEquals('3000', $config->getDefaultHttpPort());
    }

    // --- toArray edge cases ---

    public function testToArrayContainsAllExpectedKeys(): void
    {
        $config = new Config();
        $array = $config->toArray();

        $expectedKeys = [
            'defaultHttpPort',
            'defaultHttpsPort',
            'organization',
            'image',
            'noStart',
            'vars',
            'isUpgrade',
            'isLocal',
            'hostPath',
            'lockedDatabase',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, "Missing key: $key");
        }
        $this->assertCount(count($expectedKeys), $array);
    }

    public function testToArrayDefaultsMatchConstructorDefaults(): void
    {
        $config = new Config();
        $array = $config->toArray();

        $this->assertEquals('80', $array['defaultHttpPort']);
        $this->assertEquals('443', $array['defaultHttpsPort']);
        $this->assertEquals('appwrite', $array['organization']);
        $this->assertEquals('appwrite', $array['image']);
        $this->assertFalse($array['noStart']);
        $this->assertEmpty($array['vars']);
        $this->assertFalse($array['isUpgrade']);
        $this->assertFalse($array['isLocal']);
        $this->assertNull($array['hostPath']);
        $this->assertNull($array['lockedDatabase']);
    }

    // --- Multiple apply calls ---

    public function testMultipleApplyCallsAccumulate(): void
    {
        $config = new Config();

        $config->apply(['defaultHttpPort' => '1111']);
        $config->apply(['defaultHttpsPort' => '2222']);
        $config->apply(['organization' => 'org']);
        $config->apply(['isLocal' => true]);

        $this->assertEquals('1111', $config->getDefaultHttpPort());
        $this->assertEquals('2222', $config->getDefaultHttpsPort());
        $this->assertEquals('org', $config->getOrganization());
        $this->assertTrue($config->isLocal());
    }

    public function testApplyOverwritesPreviousValues(): void
    {
        $config = new Config(['defaultHttpPort' => '1111']);
        $this->assertEquals('1111', $config->getDefaultHttpPort());

        $config->apply(['defaultHttpPort' => '2222']);
        $this->assertEquals('2222', $config->getDefaultHttpPort());

        $config->apply(['defaultHttpPort' => '3333']);
        $this->assertEquals('3333', $config->getDefaultHttpPort());
    }

    // --- Vars replacement (not merge) ---

    public function testSetVarsReplacesNotMerges(): void
    {
        $config = new Config();
        $config->setVars([['name' => 'A', 'default' => '1']]);
        $config->setVars([['name' => 'B', 'default' => '2']]);

        $vars = $config->getVars();
        $this->assertCount(1, $vars);
        $this->assertEquals('B', $vars[0]['name']);
    }

    public function testApplyVarsReplacesNotMerges(): void
    {
        $config = new Config();
        $config->apply(['vars' => [['name' => 'A', 'default' => '1']]]);
        $config->apply(['vars' => [['name' => 'B', 'default' => '2']]]);

        $vars = $config->getVars();
        $this->assertCount(1, $vars);
        $this->assertEquals('B', $vars[0]['name']);
    }
}
