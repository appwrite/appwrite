<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Proxy;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Action;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;
use Utopia\DNS\Message\Record;
use Utopia\Validator\Validator;

final class RuleOwnershipTest extends TestCase
{
    private const string DOMAIN = 'shop.example.com';

    private const string PROOF = '_appwrite.shop.example.com';

    private const string TARGET = 'fra.cloud.appwrite.io';

    /**
     * @var array<string, string|false>
     */
    private array $env = [];

    protected function setUp(): void
    {
        foreach (['_APP_DNS', '_APP_DOMAIN_TARGET_CAA', '_APP_DOMAIN_TARGET_CNAME', '_APP_DOMAIN_TARGET_A', '_APP_DOMAIN_TARGET_AAAA', '_APP_DOMAIN_SITES'] as $key) {
            $this->env[$key] = \getenv($key);
            \putenv($key);
        }

        \putenv('_APP_DNS=1.1.1.1');
        \putenv('_APP_DOMAIN_TARGET_CNAME=' . self::TARGET);

        RuleOwnershipTestDNS::$zone = [self::DOMAIN => [Record::TYPE_CNAME => [self::TARGET]]];
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $key => $value) {
            \putenv($value === false ? $key : $key . '=' . $value);
        }

        RuleOwnershipTestDNS::$zone = [];
    }

    public function testDomainWithoutOwnershipProofVerifiesByItsTargetAlone(): void
    {
        $this->expectNotToPerformAssertions();

        $this->action()->exposeVerifyRule($this->rule('shop'));
    }

    public function testOwnershipProofOfTheClaimingProjectVerifies(): void
    {
        $this->expectNotToPerformAssertions();

        RuleOwnershipTestDNS::$zone[self::PROOF] = [Record::TYPE_TXT => ['shop']];

        $this->action()->exposeVerifyRule($this->rule('shop'));
    }

    public function testOwnershipProofOfAnotherProjectFailsVerification(): void
    {
        RuleOwnershipTestDNS::$zone[self::PROOF] = [Record::TYPE_TXT => ['shop']];

        try {
            $this->action()->exposeVerifyRule($this->rule('squatter'));
            $this->fail('A hostname proven to belong to another project must not verify');
        } catch (Exception $err) {
            $this->assertSame(Exception::RULE_VERIFICATION_FAILED, $err->getType());
        }
    }

    public function testOwnershipProofClaimsADomainHeldByALiveProject(): void
    {
        RuleOwnershipTestDNS::$zone[self::PROOF] = [Record::TYPE_TXT => ['shop']];

        $deleted = [];
        $rule = $this->action()->exposeCreateRule($this->rule('shop'), $this->heldDomain($deleted), new Authorization(), $this->createStub(Bus::class));

        $this->assertSame(['squatterRule'], $deleted);
        $this->assertSame('shop', $rule->getAttribute('projectId'));
    }

    public function testDomainHeldByALiveProjectStaysWithoutOwnershipProof(): void
    {
        $deleted = [];

        try {
            $this->action()->exposeCreateRule($this->rule('shop'), $this->heldDomain($deleted), new Authorization(), $this->createStub(Bus::class));
            $this->fail('A hostname held by a live project must not be claimed without proof');
        } catch (Exception $err) {
            $this->assertSame(Exception::RULE_ALREADY_EXISTS, $err->getType());
        }

        $this->assertSame([], $deleted);
    }

    public function testDomainHeldByTheClaimingProjectIsNotReplaced(): void
    {
        RuleOwnershipTestDNS::$zone[self::PROOF] = [Record::TYPE_TXT => ['shop']];

        $deleted = [];

        try {
            $this->action()->exposeCreateRule($this->rule('shop'), $this->heldDomain($deleted, 'shop'), new Authorization(), $this->createStub(Bus::class));
            $this->fail('A project proving the domain is its own must not replace the rule it already has for it');
        } catch (Exception $err) {
            $this->assertSame(Exception::RULE_ALREADY_EXISTS, $err->getType());
        }

        $this->assertSame([], $deleted);
    }

    private function action(): RuleOwnershipTestAction
    {
        return new RuleOwnershipTestAction(RuleOwnershipTestDNS::class);
    }

    private function rule(string $projectId): Document
    {
        return new Document([
            '$id' => 'rule1',
            'domain' => self::DOMAIN,
            'projectId' => $projectId,
            'projectInternalId' => '1',
            'type' => 'api',
        ]);
    }

    /**
     * Platform database where a live project already holds the domain.
     *
     * @param array<string> $deleted Collects the IDs of released rules
     * @param string $holder Project holding the domain
     */
    private function heldDomain(array &$deleted, string $holder = 'squatter'): Database
    {
        $held = new Document([
            '$id' => $holder . 'Rule',
            'domain' => self::DOMAIN,
            'projectId' => $holder,
            'projectInternalId' => '9',
        ]);

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('createDocument')->willReturnCallback(
            function (string $collection, Document $document) use (&$deleted): Document {
                if ($deleted === []) {
                    throw new Duplicate();
                }

                return $document;
            }
        );
        $dbForPlatform->method('findOne')->willReturn($held);
        $dbForPlatform->method('getDocument')->willReturnCallback(
            fn (string $collection, string $id) => $collection === 'projects' ? new Document(['$id' => $id]) : new Document()
        );
        $dbForPlatform->method('deleteDocument')->willReturnCallback(
            function (string $collection, string $id) use (&$deleted): bool {
                $deleted[] = $id;

                return true;
            }
        );

        return $dbForPlatform;
    }
}

final class RuleOwnershipTestAction extends Action
{
    public function exposeVerifyRule(Document $rule): void
    {
        $this->verifyRule($rule);
    }

    public function exposeCreateRule(Document $rule, Database $dbForPlatform, Authorization $authorization, Bus $bus): Document
    {
        return $this->createRule($rule, $dbForPlatform, $authorization, $bus);
    }
}

/**
 * DNS validator answering from an in-memory zone, so rule verification runs
 * without a resolver and without the coroutines the real validator fans out over.
 */
final class RuleOwnershipTestDNS extends Validator
{
    /**
     * @var array<string, array<int, array<string>>> Records of the zone, by name then by type
     */
    public static array $zone = [];

    /**
     * @var array<string>
     */
    public array $records = [];

    public int $count = 0;

    /**
     * @param array<string> $dnsServers
     */
    public function __construct(private string $target, private int $type = Record::TYPE_CNAME, private array $dnsServers = [])
    {
    }

    public function getDescription(): string
    {
        return 'Resolvers ' . \implode(',', $this->dnsServers) . ' found no ' . $this->type . ' record of value ' . $this->target . '.';
    }

    public function isValid(mixed $value): bool
    {
        $this->records = \is_string($value) ? (self::$zone[$value][$this->type] ?? []) : [];
        $this->count = \count($this->records);

        return \in_array($this->target, $this->records, true);
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
