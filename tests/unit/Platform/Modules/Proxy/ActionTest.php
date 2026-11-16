<?php

declare(strict_types=1);

namespace Swoole\Coroutine {
    if (!\class_exists(WaitGroup::class, false)) {
        class WaitGroup
        {
            public function add(): void
            {
            }

            public function done(): void
            {
            }

            public function wait(): void
            {
            }
        }
    }
}

namespace Swoole {
    if (!\class_exists(Coroutine::class, false)) {
        class Coroutine
        {
            public static function getCid(): int
            {
                return -1;
            }
        }
    }
}

namespace Tests\Unit\Platform\Modules\Proxy {
    require_once __DIR__ . '/../../../../../app/init/constants.php';

    use Appwrite\Extend\Exception;
    use Appwrite\Platform\Modules\Proxy\Action;
    use PHPUnit\Framework\TestCase;
    use Utopia\Config\Config;
    use Utopia\Database\Document;
    use Utopia\DNS\Message\Record;

    final class MockDNSValidator
    {
        /** @var list<array{target: string, type: int, servers: array<string>}> */
        public static array $created = [];

        public static bool $shouldPass = true;

        public int $count = 0;

        public string $reason = '';

        public function __construct(
            public string $target,
            public int $type = Record::TYPE_CNAME,
            public array $dnsServers = [],
        ) {
            self::$created[] = [
                'target' => $target,
                'type' => $type,
                'servers' => $dnsServers,
            ];
        }

        public function isValid(mixed $value): bool
        {
            return self::$shouldPass;
        }

        public function getDescription(): string
        {
            return 'Mock verification error';
        }
    }

    final class TestableAction extends Action
    {
        public function verify(Document $rule): void
        {
            $this->verifyRule($rule);
        }
    }

    final class ActionTest extends TestCase
    {
        protected array $originalEnv = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            Config::setParam('errors', require __DIR__ . '/../../../../../app/config/errors.php');
        }

        protected function setUp(): void
        {
            parent::setUp();
            MockDNSValidator::$created = [];
            MockDNSValidator::$shouldPass = true;

            $keys = [
                '_APP_DOMAIN_TARGET_CNAME',
                '_APP_DOMAIN_SITES',
                '_APP_DOMAIN_TARGET_A',
                '_APP_DOMAIN_TARGET_AAAA',
                '_APP_DOMAIN_TARGET_CAA',
                '_APP_DNS',
            ];

            foreach ($keys as $key) {
                $this->originalEnv[$key] = \getenv($key);
            }
        }

        protected function tearDown(): void
        {
            foreach ($this->originalEnv as $key => $value) {
                if ($value === false) {
                    \putenv($key);
                } else {
                    \putenv("{$key}={$value}");
                }
            }

            parent::tearDown();
        }

        private function createAction(): TestableAction
        {
            return new TestableAction(MockDNSValidator::class);
        }

        public function testVerifyRuleThrowsWhenNoTargetsConfigured(): void
        {
            \putenv('_APP_DOMAIN_TARGET_CNAME=');
            \putenv('_APP_DOMAIN_TARGET_A=');
            \putenv('_APP_DOMAIN_TARGET_AAAA=');

            $action = $this->createAction();
            $rule = new Document([
                'domain' => 'fn.example.com',
                'type' => 'deployment',
                'deploymentResourceType' => 'function',
            ]);

            $this->expectException(Exception::class);
            $this->expectExceptionCode(500);
            $this->expectExceptionMessage('At least one of domain targets environment variable must be configured.');

            $action->verify($rule);
        }

        public function testVerifyRuleUsesConfiguredARecordWhenCnameEmpty(): void
        {
            \putenv('_APP_DOMAIN_TARGET_CNAME=');
            \putenv('_APP_DOMAIN_TARGET_A=192.0.2.10');
            \putenv('_APP_DOMAIN_TARGET_AAAA=');

            $action = $this->createAction();
            $rule = new Document([
                'domain' => 'fn.example.com',
                'type' => 'deployment',
                'deploymentResourceType' => 'function',
            ]);

            $action->verify($rule);

            $this->assertCount(1, MockDNSValidator::$created);
            $this->assertSame('192.0.2.10', MockDNSValidator::$created[0]['target']);
            $this->assertSame(Record::TYPE_A, MockDNSValidator::$created[0]['type']);
        }

        public function testVerifyRuleNormalizesUrlAndWhitespaceCnameTarget(): void
        {
            \putenv('_APP_DOMAIN_TARGET_CNAME=  https://functions.appwrite.io:8443/path , ');
            \putenv('_APP_DOMAIN_TARGET_A=');
            \putenv('_APP_DOMAIN_TARGET_AAAA=');

            $action = $this->createAction();
            $rule = new Document([
                'domain' => 'fn.example.com',
                'type' => 'deployment',
                'deploymentResourceType' => 'function',
            ]);

            $action->verify($rule);

            $this->assertCount(1, MockDNSValidator::$created);
            $this->assertSame('functions.appwrite.io', MockDNSValidator::$created[0]['target']);
            $this->assertSame(Record::TYPE_CNAME, MockDNSValidator::$created[0]['type']);
        }

        public function testVerifyRuleParsesMultipleAAndAaaaTargets(): void
        {
            \putenv('_APP_DOMAIN_TARGET_CNAME=');
            \putenv('_APP_DOMAIN_TARGET_A=192.0.2.1, 198.51.100.2');
            \putenv('_APP_DOMAIN_TARGET_AAAA=2001:db8::1, 2001:db8::2');

            $action = $this->createAction();
            $rule = new Document([
                'domain' => 'fn.example.com',
                'type' => 'deployment',
                'deploymentResourceType' => 'function',
            ]);

            $action->verify($rule);

            $this->assertCount(4, MockDNSValidator::$created);
            $this->assertSame('192.0.2.1', MockDNSValidator::$created[0]['target']);
            $this->assertSame(Record::TYPE_A, MockDNSValidator::$created[0]['type']);
            $this->assertSame('198.51.100.2', MockDNSValidator::$created[1]['target']);
            $this->assertSame(Record::TYPE_A, MockDNSValidator::$created[1]['type']);
            $this->assertSame('2001:db8::1', MockDNSValidator::$created[2]['target']);
            $this->assertSame(Record::TYPE_AAAA, MockDNSValidator::$created[2]['type']);
            $this->assertSame('2001:db8::2', MockDNSValidator::$created[3]['target']);
            $this->assertSame(Record::TYPE_AAAA, MockDNSValidator::$created[3]['type']);
        }

        public function testVerifyRulePreservesFunctionTargetForFunctionRedirect(): void
        {
            \putenv('_APP_DOMAIN_TARGET_CNAME=functions.example.com');
            \putenv('_APP_DOMAIN_SITES=sites.example.com');
            \putenv('_APP_DOMAIN_TARGET_A=');
            \putenv('_APP_DOMAIN_TARGET_AAAA=');

            $action = $this->createAction();
            $rule = new Document([
                'domain' => 'redirect.example.com',
                'type' => 'redirect',
                'deploymentResourceType' => 'function',
            ]);

            $action->verify($rule);

            $this->assertCount(1, MockDNSValidator::$created);
            $this->assertSame('functions.example.com', MockDNSValidator::$created[0]['target']);
            $this->assertSame(Record::TYPE_CNAME, MockDNSValidator::$created[0]['type']);
        }

        public function testVerifyRulePreservesSiteTargetForSiteRedirect(): void
        {
            \putenv('_APP_DOMAIN_TARGET_CNAME=functions.example.com');
            \putenv('_APP_DOMAIN_SITES=sites.example.com');
            \putenv('_APP_DOMAIN_TARGET_A=');
            \putenv('_APP_DOMAIN_TARGET_AAAA=');

            $action = $this->createAction();
            $rule = new Document([
                'domain' => 'redirect.example.com',
                'type' => 'redirect',
                'deploymentResourceType' => 'site',
            ]);

            $action->verify($rule);

            $this->assertCount(1, MockDNSValidator::$created);
            $this->assertSame('sites.example.com', MockDNSValidator::$created[0]['target']);
            $this->assertSame(Record::TYPE_CNAME, MockDNSValidator::$created[0]['type']);
        }
    }
}
