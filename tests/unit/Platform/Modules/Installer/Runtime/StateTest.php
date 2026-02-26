<?php

namespace Tests\Unit\Platform\Modules\Installer\Runtime;

use Appwrite\Platform\Installer\Runtime\Config;
use Appwrite\Platform\Installer\Runtime\State;
use Appwrite\Platform\Installer\Server;
use PHPUnit\Framework\TestCase;

class StateTest extends TestCase
{
    protected ?State $state = null;
    private string $tempDir;
    private array $progressFiles = [];
    private ?string $savedEnv = null;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/appwrite-installer-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $root = dirname(__DIR__, 6);
        $this->state = new State([
            'public' => $root . '/public',
            'init' => $root . '/app/init.php',
            'views' => $root . '/app/views/install',
            'vendor' => $root . '/vendor/autoload.php',
            'installPhp' => $root . '/src/Appwrite/Platform/Tasks/Install.php',
        ]);

        // Preserve env state
        $env = getenv('APPWRITE_INSTALLER_CONFIG');
        $this->savedEnv = $env !== false ? $env : null;
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tempDir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);

        // Clean up progress files
        foreach ($this->progressFiles as $file) {
            @unlink($file);
        }

        // Clean up lock file
        @unlink(Server::INSTALLER_LOCK_FILE);
        @unlink(Server::INSTALLER_CONFIG_FILE);

        // Restore env state
        if ($this->savedEnv !== null) {
            putenv('APPWRITE_INSTALLER_CONFIG=' . $this->savedEnv);
        } else {
            putenv('APPWRITE_INSTALLER_CONFIG');
        }

        $this->state = null;
    }

    private function trackProgressFile(string $installId): void
    {
        $this->progressFiles[] = $this->state->progressFilePath($installId);
    }

    // --- sanitizeInstallId ---

    public function testSanitizeInstallIdWithValidId(): void
    {
        $this->assertEquals('abc123', $this->state->sanitizeInstallId('abc123'));
    }

    public function testSanitizeInstallIdWithSpecialChars(): void
    {
        $this->assertEquals('abc123', $this->state->sanitizeInstallId('abc!@#123'));
    }

    public function testSanitizeInstallIdWithHyphensAndUnderscores(): void
    {
        $this->assertEquals('abc-123_def', $this->state->sanitizeInstallId('abc-123_def'));
    }

    public function testSanitizeInstallIdTruncatesTo64Chars(): void
    {
        $long = str_repeat('a', 100);
        $this->assertEquals(64, strlen($this->state->sanitizeInstallId($long)));
    }

    public function testSanitizeInstallIdWithEmptyString(): void
    {
        $this->assertEquals('', $this->state->sanitizeInstallId(''));
    }

    public function testSanitizeInstallIdWithNonString(): void
    {
        $this->assertEquals('', $this->state->sanitizeInstallId(123));
        $this->assertEquals('', $this->state->sanitizeInstallId(null));
    }

    // --- hashSensitiveValue ---

    public function testHashSensitiveValueProducesConsistentHash(): void
    {
        $hash1 = $this->state->hashSensitiveValue('secret');
        $hash2 = $this->state->hashSensitiveValue('secret');
        $this->assertEquals($hash1, $hash2);
    }

    public function testHashSensitiveValueDifferentInputsDifferentHashes(): void
    {
        $hash1 = $this->state->hashSensitiveValue('secret1');
        $hash2 = $this->state->hashSensitiveValue('secret2');
        $this->assertNotEquals($hash1, $hash2);
    }

    public function testHashSensitiveValueTrimsWhitespace(): void
    {
        $hash1 = $this->state->hashSensitiveValue('secret');
        $hash2 = $this->state->hashSensitiveValue('  secret  ');
        $this->assertEquals($hash1, $hash2);
    }

    public function testHashSensitiveValueEmptyStringReturnsEmpty(): void
    {
        $this->assertEquals('', $this->state->hashSensitiveValue(''));
        $this->assertEquals('', $this->state->hashSensitiveValue('   '));
    }

    public function testHashSensitiveValueReturnsSha256(): void
    {
        $hash = $this->state->hashSensitiveValue('test');
        $this->assertEquals(64, strlen($hash)); // SHA-256 produces 64 hex chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    // --- isValidPort ---

    public function testIsValidPortWithValidPorts(): void
    {
        $this->assertTrue($this->state->isValidPort('1'));
        $this->assertTrue($this->state->isValidPort('80'));
        $this->assertTrue($this->state->isValidPort('443'));
        $this->assertTrue($this->state->isValidPort('8080'));
        $this->assertTrue($this->state->isValidPort('65535'));
    }

    public function testIsValidPortWithInvalidPorts(): void
    {
        $this->assertFalse($this->state->isValidPort('0'));
        $this->assertFalse($this->state->isValidPort('65536'));
        $this->assertFalse($this->state->isValidPort('-1'));
        $this->assertFalse($this->state->isValidPort('abc'));
        $this->assertFalse($this->state->isValidPort(''));
        $this->assertFalse($this->state->isValidPort('80.5'));
        $this->assertFalse($this->state->isValidPort('80abc'));
    }

    public function testIsValidPortWithIntegerInput(): void
    {
        $this->assertTrue($this->state->isValidPort(80));
        $this->assertTrue($this->state->isValidPort(443));
        $this->assertFalse($this->state->isValidPort(0));
    }

    // --- isValidEmailAddress ---

    public function testIsValidEmailAddressWithValidEmails(): void
    {
        $this->assertTrue($this->state->isValidEmailAddress('user@example.com'));
        $this->assertTrue($this->state->isValidEmailAddress('test.user@domain.org'));
        $this->assertTrue($this->state->isValidEmailAddress('admin+tag@example.co.uk'));
    }

    public function testIsValidEmailAddressWithInvalidEmails(): void
    {
        $this->assertFalse($this->state->isValidEmailAddress(''));
        $this->assertFalse($this->state->isValidEmailAddress('notanemail'));
        $this->assertFalse($this->state->isValidEmailAddress('@domain.com'));
        $this->assertFalse($this->state->isValidEmailAddress('user@'));
    }

    // --- isValidPassword ---

    public function testIsValidPasswordWithValidPasswords(): void
    {
        $this->assertTrue($this->state->isValidPassword('12345678'));
        $this->assertTrue($this->state->isValidPassword('abcdefgh'));
        $this->assertTrue($this->state->isValidPassword('P@ssw0rd!'));
    }

    public function testIsValidPasswordWithInvalidPasswords(): void
    {
        $this->assertFalse($this->state->isValidPassword(''));
        $this->assertFalse($this->state->isValidPassword('short'));
        $this->assertFalse($this->state->isValidPassword('1234567')); // 7 chars
        $this->assertFalse($this->state->isValidPassword('        ')); // 8 spaces, no non-whitespace
    }

    // --- isValidSecretKey ---

    public function testIsValidSecretKeyWithValidKeys(): void
    {
        $this->assertTrue($this->state->isValidSecretKey('a'));
        $this->assertTrue($this->state->isValidSecretKey('my-secret-key'));
        $this->assertTrue($this->state->isValidSecretKey(str_repeat('x', 64)));
    }

    public function testIsValidSecretKeyWithInvalidKeys(): void
    {
        $this->assertFalse($this->state->isValidSecretKey(''));
        $this->assertFalse($this->state->isValidSecretKey(str_repeat('x', 65)));
    }

    // --- isValidAccountName ---

    public function testIsValidAccountNameWithValidNames(): void
    {
        $this->assertTrue($this->state->isValidAccountName('John'));
        $this->assertTrue($this->state->isValidAccountName('a'));
    }

    public function testIsValidAccountNameWithInvalidNames(): void
    {
        $this->assertFalse($this->state->isValidAccountName(''));
        $this->assertFalse($this->state->isValidAccountName('   '));
    }

    // --- isValidAppDomainInput ---

    public function testIsValidAppDomainInputWithValidDomains(): void
    {
        $this->assertTrue($this->state->isValidAppDomainInput('localhost'));
        $this->assertTrue($this->state->isValidAppDomainInput('example.com'));
        $this->assertTrue($this->state->isValidAppDomainInput('sub.example.com'));
        $this->assertTrue($this->state->isValidAppDomainInput('127.0.0.1'));
        $this->assertTrue($this->state->isValidAppDomainInput('192.168.1.1'));
    }

    public function testIsValidAppDomainInputWithPort(): void
    {
        $this->assertTrue($this->state->isValidAppDomainInput('localhost:8080'));
        $this->assertTrue($this->state->isValidAppDomainInput('example.com:443'));
        $this->assertTrue($this->state->isValidAppDomainInput('127.0.0.1:3000'));
    }

    public function testIsValidAppDomainInputWithIpv6(): void
    {
        $this->assertTrue($this->state->isValidAppDomainInput('[::1]'));
        $this->assertTrue($this->state->isValidAppDomainInput('[::1]:8080'));
    }

    public function testIsValidAppDomainInputWithInvalidDomains(): void
    {
        $this->assertFalse($this->state->isValidAppDomainInput(''));
        $this->assertFalse($this->state->isValidAppDomainInput('   '));
        $this->assertFalse($this->state->isValidAppDomainInput('localhost:99999'));
        $this->assertFalse($this->state->isValidAppDomainInput('localhost:0'));
        $this->assertFalse($this->state->isValidAppDomainInput('host:port:extra'));
    }

    // --- isValidDatabaseAdapter ---

    public function testIsValidDatabaseAdapterWithValidAdapters(): void
    {
        $this->assertTrue($this->state->isValidDatabaseAdapter('mongodb'));
        $this->assertTrue($this->state->isValidDatabaseAdapter('mariadb'));
    }

    public function testIsValidDatabaseAdapterWithInvalidAdapters(): void
    {
        $this->assertFalse($this->state->isValidDatabaseAdapter(''));
        $this->assertFalse($this->state->isValidDatabaseAdapter('mysql'));
        $this->assertFalse($this->state->isValidDatabaseAdapter('postgres'));
        $this->assertFalse($this->state->isValidDatabaseAdapter('MongoDB')); // case sensitive
    }

    // --- progressFilePath ---

    public function testProgressFilePathFormat(): void
    {
        $path = $this->state->progressFilePath('test123');
        $this->assertStringContainsString('appwrite-install-test123.json', $path);
        $this->assertStringStartsWith(sys_get_temp_dir(), $path);
    }

    // --- readProgressFile / writeProgressFile ---

    public function testReadProgressFileReturnsDefaultForMissing(): void
    {
        $data = $this->state->readProgressFile('nonexistent-id-' . uniqid());
        $this->assertIsArray($data);
        $this->assertArrayHasKey('installId', $data);
        $this->assertArrayHasKey('steps', $data);
        $this->assertEmpty($data['steps']);
    }

    public function testWriteAndReadProgressFile(): void
    {
        $installId = 'test-' . uniqid();

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_ENV_VARS,
            'status' => Server::STATUS_IN_PROGRESS,
            'message' => 'Writing environment variables',
            'updatedAt' => time(),
        ]);

        $data = $this->state->readProgressFile($installId);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('steps', $data);
        $this->assertArrayHasKey(Server::STEP_ENV_VARS, $data['steps']);
        $this->assertEquals(Server::STATUS_IN_PROGRESS, $data['steps'][Server::STEP_ENV_VARS]['status']);
        $this->assertEquals('Writing environment variables', $data['steps'][Server::STEP_ENV_VARS]['message']);

        // Cleanup
        @unlink($this->state->progressFilePath($installId));
    }

    public function testWriteProgressFileAccumulatesSteps(): void
    {
        $installId = 'test-multi-' . uniqid();

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_ENV_VARS,
            'status' => Server::STATUS_COMPLETED,
            'message' => 'Done',
            'updatedAt' => time(),
        ]);

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_DOCKER_COMPOSE,
            'status' => Server::STATUS_IN_PROGRESS,
            'message' => 'Generating compose file',
            'updatedAt' => time(),
        ]);

        $data = $this->state->readProgressFile($installId);
        $this->assertCount(2, $data['steps']);
        $this->assertArrayHasKey(Server::STEP_ENV_VARS, $data['steps']);
        $this->assertArrayHasKey(Server::STEP_DOCKER_COMPOSE, $data['steps']);

        // Cleanup
        @unlink($this->state->progressFilePath($installId));
    }

    public function testWriteProgressFileStoresPayload(): void
    {
        $installId = 'test-payload-' . uniqid();

        $this->state->writeProgressFile($installId, [
            'payload' => [
                'httpPort' => '80',
                'httpsPort' => '443',
                'database' => 'mariadb',
            ],
            'step' => 'start',
            'status' => Server::STATUS_IN_PROGRESS,
            'message' => 'Started',
            'updatedAt' => time(),
        ]);

        $data = $this->state->readProgressFile($installId);
        $this->assertArrayHasKey('payload', $data);
        $this->assertEquals('80', $data['payload']['httpPort']);
        $this->assertEquals('443', $data['payload']['httpsPort']);
        $this->assertEquals('mariadb', $data['payload']['database']);
        $this->assertArrayHasKey('startedAt', $data);

        // Cleanup
        @unlink($this->state->progressFilePath($installId));
    }

    public function testWriteProgressFileStoresErrorMessage(): void
    {
        $installId = 'test-error-' . uniqid();

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_DOCKER_CONTAINERS,
            'status' => Server::STATUS_ERROR,
            'message' => 'Container failed to start',
            'updatedAt' => time(),
        ]);

        $data = $this->state->readProgressFile($installId);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Container failed to start', $data['error']);

        // Cleanup
        @unlink($this->state->progressFilePath($installId));
    }

    public function testWriteProgressFileStoresDetails(): void
    {
        $installId = 'test-details-' . uniqid();

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_DOCKER_COMPOSE,
            'status' => Server::STATUS_COMPLETED,
            'message' => 'Done',
            'details' => ['composeFile' => '/path/to/docker-compose.yml'],
            'updatedAt' => time(),
        ]);

        $data = $this->state->readProgressFile($installId);
        $this->assertArrayHasKey('details', $data);
        $this->assertArrayHasKey(Server::STEP_DOCKER_COMPOSE, $data['details']);
        $this->assertEquals('/path/to/docker-compose.yml', $data['details'][Server::STEP_DOCKER_COMPOSE]['composeFile']);

        // Cleanup
        @unlink($this->state->progressFilePath($installId));
    }

    // --- buildConfig ---

    public function testBuildConfigReturnsConfigInstance(): void
    {
        // Clear env to avoid interference
        putenv('APPWRITE_INSTALLER_CONFIG');

        $config = $this->state->buildConfig([], false);
        $this->assertInstanceOf(Config::class, $config);
    }

    public function testBuildConfigAppliesOverrides(): void
    {
        putenv('APPWRITE_INSTALLER_CONFIG');

        $config = $this->state->buildConfig(['defaultHttpPort' => '9090'], false);
        $this->assertEquals('9090', $config->getDefaultHttpPort());
    }

    public function testBuildConfigFromEnvVar(): void
    {
        $envData = json_encode([
            'defaultHttpPort' => '8888',
            'isUpgrade' => true,
        ]);
        putenv('APPWRITE_INSTALLER_CONFIG=' . $envData);

        $config = $this->state->buildConfig([], true);
        $this->assertEquals('8888', $config->getDefaultHttpPort());
        $this->assertTrue($config->isUpgrade());

        // Cleanup
        putenv('APPWRITE_INSTALLER_CONFIG');
    }

    public function testBuildConfigOverridesEnv(): void
    {
        $envData = json_encode(['defaultHttpPort' => '8888']);
        putenv('APPWRITE_INSTALLER_CONFIG=' . $envData);

        $config = $this->state->buildConfig(['defaultHttpPort' => '7777'], true);
        $this->assertEquals('7777', $config->getDefaultHttpPort());

        // Cleanup
        putenv('APPWRITE_INSTALLER_CONFIG');
    }

    // --- sanitizeInstallId edge cases ---

    public function testSanitizeInstallIdWithOnlySpecialChars(): void
    {
        $this->assertEquals('', $this->state->sanitizeInstallId('!@#$%^&*()'));
    }

    public function testSanitizeInstallIdWithUnicode(): void
    {
        // Unicode letters are stripped byte-by-byte, only ASCII alphanum + hyphen + underscore kept
        // 'é' is 2 bytes (0xC3 0xA9), both stripped => 'héllo' becomes 'hllo'
        $this->assertEquals('hllo', $this->state->sanitizeInstallId('héllo'));
    }

    public function testSanitizeInstallIdWithExactly64Chars(): void
    {
        $exact = str_repeat('b', 64);
        $this->assertEquals($exact, $this->state->sanitizeInstallId($exact));
        $this->assertEquals(64, strlen($this->state->sanitizeInstallId($exact)));
    }

    public function testSanitizeInstallIdWithBooleanInput(): void
    {
        $this->assertEquals('', $this->state->sanitizeInstallId(true));
        $this->assertEquals('', $this->state->sanitizeInstallId(false));
    }

    public function testSanitizeInstallIdWithArrayInput(): void
    {
        $this->assertEquals('', $this->state->sanitizeInstallId([]));
    }

    public function testSanitizeInstallIdPreservesCase(): void
    {
        $this->assertEquals('AbCdEf', $this->state->sanitizeInstallId('AbCdEf'));
    }

    // --- isValidPort edge cases ---

    public function testIsValidPortBoundaryValues(): void
    {
        $this->assertTrue($this->state->isValidPort('1'));
        $this->assertTrue($this->state->isValidPort('65535'));
        $this->assertFalse($this->state->isValidPort('0'));
        $this->assertFalse($this->state->isValidPort('65536'));
    }

    public function testIsValidPortWithLeadingZeros(): void
    {
        // '080' is digits-only and parses to 80 which is in range
        $this->assertTrue($this->state->isValidPort('080'));
        // '00' parses to 0, which is out of range
        $this->assertFalse($this->state->isValidPort('00'));
    }

    public function testIsValidPortWithWhitespace(): void
    {
        // Contains non-digit characters
        $this->assertFalse($this->state->isValidPort(' 80'));
        $this->assertFalse($this->state->isValidPort('80 '));
        $this->assertFalse($this->state->isValidPort(' 80 '));
    }

    public function testIsValidPortWithNegativeNumber(): void
    {
        $this->assertFalse($this->state->isValidPort('-80'));
        $this->assertFalse($this->state->isValidPort('-1'));
    }

    public function testIsValidPortWithVeryLargeNumber(): void
    {
        $this->assertFalse($this->state->isValidPort('999999'));
        $this->assertFalse($this->state->isValidPort('100000'));
    }

    // --- isValidPassword edge cases ---

    public function testIsValidPasswordExactly8Chars(): void
    {
        $this->assertTrue($this->state->isValidPassword('12345678'));
        $this->assertFalse($this->state->isValidPassword('1234567'));
    }

    public function testIsValidPasswordWithTabsAndNewlines(): void
    {
        // Tabs/newlines count as whitespace, but need at least one non-whitespace
        $this->assertFalse($this->state->isValidPassword("\t\t\t\t\t\t\t\t")); // 8 tabs
        $this->assertTrue($this->state->isValidPassword("\t\t\t\ttest")); // mixed
    }

    public function testIsValidPasswordWithMixedWhitespaceAndChars(): void
    {
        $this->assertTrue($this->state->isValidPassword('   a    ')); // has non-whitespace
    }

    // --- isValidSecretKey edge cases ---

    public function testIsValidSecretKeyExactly64Chars(): void
    {
        $this->assertTrue($this->state->isValidSecretKey(str_repeat('a', 64)));
    }

    public function testIsValidSecretKeyWithWhitespace(): void
    {
        // Whitespace-only is still non-empty and <= 64 chars
        $this->assertTrue($this->state->isValidSecretKey(' '));
        $this->assertTrue($this->state->isValidSecretKey('   '));
    }

    // --- isValidAppDomainInput edge cases ---

    public function testIsValidAppDomainInputWithEmptyPort(): void
    {
        // "host:" splits to ['host', ''] - empty port with null check
        $this->assertTrue($this->state->isValidAppDomainInput('localhost:'));
    }

    public function testIsValidAppDomainInputWithIpv4Address(): void
    {
        $this->assertTrue($this->state->isValidAppDomainInput('10.0.0.1'));
        $this->assertTrue($this->state->isValidAppDomainInput('255.255.255.255'));
        $this->assertTrue($this->state->isValidAppDomainInput('0.0.0.0'));
    }

    public function testIsValidAppDomainInputIpv6WithoutBrackets(): void
    {
        // Raw IPv6 without brackets: "::1" has two colons, so count($parts) > 2 => false
        $this->assertFalse($this->state->isValidAppDomainInput('::1'));
        $this->assertFalse($this->state->isValidAppDomainInput('fe80::1'));
    }

    public function testIsValidAppDomainInputIpv6MalformedBrackets(): void
    {
        $this->assertFalse($this->state->isValidAppDomainInput('['));
        $this->assertFalse($this->state->isValidAppDomainInput('[]'));
        $this->assertFalse($this->state->isValidAppDomainInput('[invalid'));
    }

    public function testIsValidAppDomainInputWithSubdomains(): void
    {
        $this->assertTrue($this->state->isValidAppDomainInput('a.b.c.d.example.com'));
        $this->assertTrue($this->state->isValidAppDomainInput('my-app.example.io:8080'));
    }

    public function testIsValidAppDomainInputWithInvalidPortNumber(): void
    {
        $this->assertFalse($this->state->isValidAppDomainInput('localhost:abc'));
        $this->assertFalse($this->state->isValidAppDomainInput('localhost:70000'));
        $this->assertFalse($this->state->isValidAppDomainInput('[::1]:70000'));
    }

    // --- isValidDatabaseAdapter edge cases ---

    public function testIsValidDatabaseAdapterWithWhitespace(): void
    {
        $this->assertFalse($this->state->isValidDatabaseAdapter(' mongodb'));
        $this->assertFalse($this->state->isValidDatabaseAdapter('mariadb '));
    }

    public function testIsValidDatabaseAdapterCaseSensitivity(): void
    {
        $this->assertFalse($this->state->isValidDatabaseAdapter('MongoDB'));
        $this->assertFalse($this->state->isValidDatabaseAdapter('MariaDB'));
        $this->assertFalse($this->state->isValidDatabaseAdapter('MONGODB'));
    }

    // --- readProgressFile edge cases ---

    public function testReadProgressFileWithCorruptedJson(): void
    {
        $installId = 'test-corrupt-' . uniqid();
        $this->trackProgressFile($installId);
        $path = $this->state->progressFilePath($installId);
        file_put_contents($path, 'not valid json {{{');

        $data = $this->state->readProgressFile($installId);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('installId', $data);
        $this->assertArrayHasKey('steps', $data);
        $this->assertEmpty($data['steps']);
    }

    public function testReadProgressFileWithEmptyFile(): void
    {
        $installId = 'test-empty-' . uniqid();
        $this->trackProgressFile($installId);
        $path = $this->state->progressFilePath($installId);
        file_put_contents($path, '');

        $data = $this->state->readProgressFile($installId);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('installId', $data);
        $this->assertEmpty($data['steps']);
    }

    public function testReadProgressFileWithJsonScalar(): void
    {
        $installId = 'test-scalar-' . uniqid();
        $this->trackProgressFile($installId);
        $path = $this->state->progressFilePath($installId);
        file_put_contents($path, '"just a string"');

        $data = $this->state->readProgressFile($installId);
        $this->assertIsArray($data);
        $this->assertEmpty($data['steps']);
    }

    // --- writeProgressFile edge cases ---

    public function testWriteProgressFileOverwritesExistingStep(): void
    {
        $installId = 'test-overwrite-' . uniqid();
        $this->trackProgressFile($installId);

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_ENV_VARS,
            'status' => Server::STATUS_IN_PROGRESS,
            'message' => 'Working...',
            'updatedAt' => time(),
        ]);

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_ENV_VARS,
            'status' => Server::STATUS_COMPLETED,
            'message' => 'Done!',
            'updatedAt' => time(),
        ]);

        $data = $this->state->readProgressFile($installId);
        $this->assertCount(1, $data['steps']); // Still 1 step, overwritten
        $this->assertEquals(Server::STATUS_COMPLETED, $data['steps'][Server::STEP_ENV_VARS]['status']);
        $this->assertEquals('Done!', $data['steps'][Server::STEP_ENV_VARS]['message']);
    }

    public function testWriteProgressFileWithEmptyStep(): void
    {
        $installId = 'test-emptystep-' . uniqid();
        $this->trackProgressFile($installId);

        $this->state->writeProgressFile($installId, [
            'step' => '',
            'status' => Server::STATUS_IN_PROGRESS,
            'message' => 'No step name',
            'updatedAt' => time(),
        ]);

        $data = $this->state->readProgressFile($installId);
        // Empty step name treated as falsy, should not add to steps
        $this->assertEmpty($data['steps']);
    }

    public function testWriteProgressFilePreservesPayloadAcrossWrites(): void
    {
        $installId = 'test-persist-' . uniqid();
        $this->trackProgressFile($installId);

        $this->state->writeProgressFile($installId, [
            'payload' => ['httpPort' => '80', 'database' => 'mongodb'],
            'step' => 'start',
            'status' => Server::STATUS_IN_PROGRESS,
            'message' => 'Starting',
            'updatedAt' => time(),
        ]);

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_ENV_VARS,
            'status' => Server::STATUS_COMPLETED,
            'message' => 'Env done',
            'updatedAt' => time(),
        ]);

        $data = $this->state->readProgressFile($installId);
        // Payload from first write should still be present
        $this->assertArrayHasKey('payload', $data);
        $this->assertEquals('80', $data['payload']['httpPort']);
        $this->assertEquals('mongodb', $data['payload']['database']);
        // Both steps should exist
        $this->assertArrayHasKey('start', $data['steps']);
        $this->assertArrayHasKey(Server::STEP_ENV_VARS, $data['steps']);
    }

    public function testWriteProgressFileUpdatesTimestamp(): void
    {
        $installId = 'test-time-' . uniqid();
        $this->trackProgressFile($installId);
        $now = time();

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_ENV_VARS,
            'status' => Server::STATUS_IN_PROGRESS,
            'message' => 'test',
            'updatedAt' => $now,
        ]);

        $data = $this->state->readProgressFile($installId);
        $this->assertEquals($now, $data['updatedAt']);
    }

    public function testWriteProgressFileStartedAtOnlySetOnce(): void
    {
        $installId = 'test-startedat-' . uniqid();
        $this->trackProgressFile($installId);
        $firstTime = time() - 100;

        // First write with payload sets startedAt
        $this->state->writeProgressFile($installId, [
            'payload' => ['httpPort' => '80'],
            'step' => 'start',
            'status' => Server::STATUS_IN_PROGRESS,
            'message' => 'Starting',
            'updatedAt' => $firstTime,
        ]);

        $data = $this->state->readProgressFile($installId);
        $startedAt = $data['startedAt'];

        // Second write with payload should NOT overwrite startedAt
        $this->state->writeProgressFile($installId, [
            'payload' => ['httpPort' => '80'],
            'step' => Server::STEP_ENV_VARS,
            'status' => Server::STATUS_IN_PROGRESS,
            'message' => 'Env',
            'updatedAt' => time(),
        ]);

        $data = $this->state->readProgressFile($installId);
        $this->assertEquals($startedAt, $data['startedAt']);
    }

    // --- Global lock: reserveGlobalLock / updateGlobalLock ---

    public function testReserveGlobalLockFirstLockSucceeds(): void
    {
        @unlink(Server::INSTALLER_LOCK_FILE);
        $installId = 'lock-test-' . uniqid();
        $result = $this->state->reserveGlobalLock($installId);
        $this->assertEquals('ok', $result);
    }

    public function testReserveGlobalLockSameIdCanRelock(): void
    {
        @unlink(Server::INSTALLER_LOCK_FILE);
        $installId = 'lock-relock-' . uniqid();

        $result1 = $this->state->reserveGlobalLock($installId);
        $this->assertEquals('ok', $result1);

        // Same ID can re-reserve
        $result2 = $this->state->reserveGlobalLock($installId);
        $this->assertEquals('ok', $result2);
    }

    public function testReserveGlobalLockDifferentIdBlocked(): void
    {
        @unlink(Server::INSTALLER_LOCK_FILE);
        $installId1 = 'lock-id1-' . uniqid();
        $installId2 = 'lock-id2-' . uniqid();

        $result1 = $this->state->reserveGlobalLock($installId1);
        $this->assertEquals('ok', $result1);

        // Different ID should be blocked
        $result2 = $this->state->reserveGlobalLock($installId2);
        $this->assertEquals('locked', $result2);
    }

    public function testReserveGlobalLockAfterCompleted(): void
    {
        @unlink(Server::INSTALLER_LOCK_FILE);
        $installId1 = 'lock-done-' . uniqid();
        $installId2 = 'lock-new-' . uniqid();

        $this->state->reserveGlobalLock($installId1);
        $this->state->updateGlobalLock($installId1, Server::STATUS_COMPLETED);

        // After completion, a new install should be able to lock
        $result = $this->state->reserveGlobalLock($installId2);
        $this->assertEquals('ok', $result);
    }

    public function testReserveGlobalLockAfterError(): void
    {
        @unlink(Server::INSTALLER_LOCK_FILE);
        $installId1 = 'lock-err-' . uniqid();
        $installId2 = 'lock-retry-' . uniqid();

        $this->state->reserveGlobalLock($installId1);
        $this->state->updateGlobalLock($installId1, Server::STATUS_ERROR);

        // After error, a new install should be able to lock
        $result = $this->state->reserveGlobalLock($installId2);
        $this->assertEquals('ok', $result);
    }

    public function testReserveGlobalLockExpiredLockAllowsNew(): void
    {
        @unlink(Server::INSTALLER_LOCK_FILE);

        // Manually write an expired lock (updatedAt way in the past)
        $expiredLock = [
            'installId' => 'expired-lock',
            'status' => Server::STATUS_IN_PROGRESS,
            'updatedAt' => time() - 7200, // 2 hours ago, timeout is 1 hour
        ];
        file_put_contents(Server::INSTALLER_LOCK_FILE, json_encode($expiredLock));

        $newId = 'lock-after-expired-' . uniqid();
        $result = $this->state->reserveGlobalLock($newId);
        $this->assertEquals('ok', $result);
    }

    public function testUpdateGlobalLockUpdatesOwnLock(): void
    {
        @unlink(Server::INSTALLER_LOCK_FILE);
        $installId = 'lock-update-' . uniqid();

        $this->state->reserveGlobalLock($installId);
        $this->state->updateGlobalLock($installId, Server::STATUS_COMPLETED);

        // Read lock file directly to verify
        $contents = file_get_contents(Server::INSTALLER_LOCK_FILE);
        $this->assertNotFalse($contents);
        $lock = json_decode($contents, true);
        $this->assertIsArray($lock);
        $this->assertEquals($installId, $lock['installId']);
        $this->assertEquals(Server::STATUS_COMPLETED, $lock['status']);
    }

    public function testUpdateGlobalLockIgnoresDifferentId(): void
    {
        @unlink(Server::INSTALLER_LOCK_FILE);
        $installId1 = 'lock-owner-' . uniqid();
        $installId2 = 'lock-intruder-' . uniqid();

        $this->state->reserveGlobalLock($installId1);

        // Attempt to update with a different ID should be silently ignored
        $this->state->updateGlobalLock($installId2, Server::STATUS_COMPLETED);

        // Original lock should still be in progress
        $contents = file_get_contents(Server::INSTALLER_LOCK_FILE);
        $lock = json_decode($contents, true);
        $this->assertEquals($installId1, $lock['installId']);
        $this->assertEquals(Server::STATUS_IN_PROGRESS, $lock['status']);
    }

    // --- applyEnvConfig ---

    public function testApplyEnvConfigWithConfigObject(): void
    {
        putenv('APPWRITE_INSTALLER_CONFIG');
        @unlink(Server::INSTALLER_CONFIG_FILE);

        $cfg = new Config(['defaultHttpPort' => '5555', 'isLocal' => true]);
        $this->state->applyEnvConfig($cfg);

        // Verify env var was set
        $envVal = getenv('APPWRITE_INSTALLER_CONFIG');
        $this->assertNotFalse($envVal);

        $decoded = json_decode($envVal, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('5555', $decoded['defaultHttpPort']);
        $this->assertTrue($decoded['isLocal']);

        // Verify config file was written
        $this->assertFileExists(Server::INSTALLER_CONFIG_FILE);
        $fileContents = file_get_contents(Server::INSTALLER_CONFIG_FILE);
        $this->assertNotFalse($fileContents);
        $fileDecoded = json_decode($fileContents, true);
        $this->assertEquals('5555', $fileDecoded['defaultHttpPort']);
    }

    public function testApplyEnvConfigWithArray(): void
    {
        putenv('APPWRITE_INSTALLER_CONFIG');
        @unlink(Server::INSTALLER_CONFIG_FILE);

        $this->state->applyEnvConfig(['defaultHttpPort' => '6666']);

        $envVal = getenv('APPWRITE_INSTALLER_CONFIG');
        $this->assertNotFalse($envVal);
        $decoded = json_decode($envVal, true);
        $this->assertEquals('6666', $decoded['defaultHttpPort']);
    }

    public function testApplyEnvConfigThenBuildConfigReadsIt(): void
    {
        putenv('APPWRITE_INSTALLER_CONFIG');
        @unlink(Server::INSTALLER_CONFIG_FILE);

        $cfg = new Config(['defaultHttpPort' => '4444', 'isUpgrade' => true]);
        $this->state->applyEnvConfig($cfg);

        // buildConfig with useEnv=true should pick up the env var
        $rebuilt = $this->state->buildConfig([], true);
        $this->assertEquals('4444', $rebuilt->getDefaultHttpPort());
        $this->assertTrue($rebuilt->isUpgrade());
    }

    // --- buildConfig edge cases ---

    public function testBuildConfigWithInvalidEnvJson(): void
    {
        putenv('APPWRITE_INSTALLER_CONFIG=not-valid-json');

        // Should fall back to config file (or defaults if file doesn't exist)
        @unlink(Server::INSTALLER_CONFIG_FILE);
        $config = $this->state->buildConfig([], true);
        // Should get defaults since both env and file are invalid/missing
        $this->assertEquals('80', $config->getDefaultHttpPort());
    }

    public function testBuildConfigWithEmptyEnvVar(): void
    {
        putenv('APPWRITE_INSTALLER_CONFIG=');

        @unlink(Server::INSTALLER_CONFIG_FILE);
        $config = $this->state->buildConfig([], true);
        $this->assertEquals('80', $config->getDefaultHttpPort());
    }

    public function testBuildConfigFallsBackToConfigFile(): void
    {
        putenv('APPWRITE_INSTALLER_CONFIG');

        // Write a config file
        $data = json_encode(['defaultHttpPort' => '3333']);
        file_put_contents(Server::INSTALLER_CONFIG_FILE, $data);

        $config = $this->state->buildConfig([], true);
        $this->assertEquals('3333', $config->getDefaultHttpPort());
    }

    public function testBuildConfigWithCorruptedConfigFile(): void
    {
        putenv('APPWRITE_INSTALLER_CONFIG');

        file_put_contents(Server::INSTALLER_CONFIG_FILE, 'garbage data {{{');

        $config = $this->state->buildConfig([], true);
        // Should get defaults
        $this->assertEquals('80', $config->getDefaultHttpPort());
    }

    public function testBuildConfigWithEmptyConfigFile(): void
    {
        putenv('APPWRITE_INSTALLER_CONFIG');

        file_put_contents(Server::INSTALLER_CONFIG_FILE, '');

        $config = $this->state->buildConfig([], true);
        $this->assertEquals('80', $config->getDefaultHttpPort());
    }

    public function testBuildConfigUseEnvFalseIgnoresEnvAndFile(): void
    {
        putenv('APPWRITE_INSTALLER_CONFIG=' . json_encode(['defaultHttpPort' => '9999']));
        file_put_contents(Server::INSTALLER_CONFIG_FILE, json_encode(['defaultHttpPort' => '8888']));

        $config = $this->state->buildConfig([], false);
        // Neither env nor file should be used
        $this->assertEquals('80', $config->getDefaultHttpPort());
    }

    public function testBuildConfigWithJsonScalarEnvVar(): void
    {
        // A JSON scalar (string) is not an array, so decoding succeeds but is_array fails
        putenv('APPWRITE_INSTALLER_CONFIG="just a string"');
        @unlink(Server::INSTALLER_CONFIG_FILE);

        $config = $this->state->buildConfig([], true);
        $this->assertEquals('80', $config->getDefaultHttpPort());
    }

    // --- hashSensitiveValue edge cases ---

    public function testHashSensitiveValueWithNewlines(): void
    {
        // Newlines are not stripped by trim but surrounding whitespace is
        $hash1 = $this->state->hashSensitiveValue("line1\nline2");
        $hash2 = $this->state->hashSensitiveValue("line1\nline2");
        $this->assertEquals($hash1, $hash2);
        $this->assertNotEmpty($hash1);
    }

    public function testHashSensitiveValueWithOnlyNewline(): void
    {
        // A newline is not whitespace that trim() removes? Actually trim() removes \n
        // "\n" trimmed becomes "" => should return ''
        $this->assertEquals('', $this->state->hashSensitiveValue("\n"));
    }

    // --- isValidEmailAddress edge cases ---

    public function testIsValidEmailAddressWithUnicodeLocal(): void
    {
        // PHP's FILTER_VALIDATE_EMAIL does not support internationalized emails
        $this->assertFalse($this->state->isValidEmailAddress('ünïcödé@example.com'));
    }

    public function testIsValidEmailAddressWithDoubleAt(): void
    {
        $this->assertFalse($this->state->isValidEmailAddress('user@@example.com'));
    }

    public function testIsValidEmailAddressWithSpaces(): void
    {
        $this->assertFalse($this->state->isValidEmailAddress('user @example.com'));
        $this->assertFalse($this->state->isValidEmailAddress('user@ example.com'));
    }

    // --- isValidAccountName edge cases ---

    public function testIsValidAccountNameWithOnlyTabs(): void
    {
        $this->assertFalse($this->state->isValidAccountName("\t\t"));
    }

    public function testIsValidAccountNameWithMixedWhitespace(): void
    {
        $this->assertTrue($this->state->isValidAccountName(" a "));
    }

    // --- progressFilePath edge cases ---

    public function testProgressFilePathWithSpecialCharsInId(): void
    {
        // The ID would normally be sanitized before this call, but the method itself
        // just concatenates
        $path = $this->state->progressFilePath('test-with-special');
        $this->assertStringContainsString('appwrite-install-test-with-special.json', $path);
    }

    public function testProgressFilePathWithEmptyId(): void
    {
        $path = $this->state->progressFilePath('');
        $this->assertStringContainsString('appwrite-install-.json', $path);
    }

    // --- writeProgressFile with non-error status doesn't set error key ---

    public function testWriteProgressFileCompletedDoesNotSetError(): void
    {
        $installId = 'test-noerror-' . uniqid();
        $this->trackProgressFile($installId);

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_ENV_VARS,
            'status' => Server::STATUS_COMPLETED,
            'message' => 'All good',
            'updatedAt' => time(),
        ]);

        $data = $this->state->readProgressFile($installId);
        $this->assertArrayNotHasKey('error', $data);
    }

    public function testWriteProgressFileInProgressDoesNotSetError(): void
    {
        $installId = 'test-noerrip-' . uniqid();
        $this->trackProgressFile($installId);

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_DOCKER_COMPOSE,
            'status' => Server::STATUS_IN_PROGRESS,
            'message' => 'Working',
            'updatedAt' => time(),
        ]);

        $data = $this->state->readProgressFile($installId);
        $this->assertArrayNotHasKey('error', $data);
    }

    // --- writeProgressFile with no step or empty payload ---

    public function testWriteProgressFileWithNoStep(): void
    {
        $installId = 'test-nostep-' . uniqid();
        $this->trackProgressFile($installId);

        $this->state->writeProgressFile($installId, [
            'status' => Server::STATUS_IN_PROGRESS,
            'message' => 'No step provided',
            'updatedAt' => time(),
        ]);

        $data = $this->state->readProgressFile($installId);
        // No step key means no step should be recorded
        $this->assertEmpty($data['steps']);
        // But updatedAt should still be set
        $this->assertArrayHasKey('updatedAt', $data);
    }

    // --- Full lifecycle: lock -> progress -> complete ---

    public function testFullInstallationLifecycle(): void
    {
        @unlink(Server::INSTALLER_LOCK_FILE);
        $installId = 'lifecycle-' . uniqid();
        $this->trackProgressFile($installId);

        // 1. Reserve lock
        $lockResult = $this->state->reserveGlobalLock($installId);
        $this->assertEquals('ok', $lockResult);

        // 2. Write progress through multiple steps
        $this->state->writeProgressFile($installId, [
            'payload' => ['httpPort' => '80', 'database' => 'mongodb'],
            'step' => 'start',
            'status' => Server::STATUS_IN_PROGRESS,
            'message' => 'Started',
            'updatedAt' => time(),
        ]);

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_ENV_VARS,
            'status' => Server::STATUS_COMPLETED,
            'message' => 'Env vars written',
            'updatedAt' => time(),
        ]);

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_DOCKER_COMPOSE,
            'status' => Server::STATUS_COMPLETED,
            'message' => 'Compose generated',
            'updatedAt' => time(),
        ]);

        $this->state->writeProgressFile($installId, [
            'step' => Server::STEP_DOCKER_CONTAINERS,
            'status' => Server::STATUS_COMPLETED,
            'message' => 'Containers started',
            'updatedAt' => time(),
        ]);

        // 3. Verify progress
        $data = $this->state->readProgressFile($installId);
        $this->assertCount(4, $data['steps']); // start + 3 steps
        $this->assertArrayHasKey('payload', $data);
        $this->assertArrayHasKey('startedAt', $data);

        // 4. Complete the lock
        $this->state->updateGlobalLock($installId, Server::STATUS_COMPLETED);

        // 5. Verify a new install can now proceed
        $newId = 'lifecycle-new-' . uniqid();
        $this->trackProgressFile($newId);
        $newResult = $this->state->reserveGlobalLock($newId);
        $this->assertEquals('ok', $newResult);
    }
}
