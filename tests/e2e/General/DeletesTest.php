<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Platform\Workers\Deletes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\DI\Container;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;

/**
 * A queued rule deletion can outlive its rule: the domain may already have been
 * recreated by the time the worker runs. Lives in e2e (not unit) because the
 * worker resolves its injections from the running stack's platform database.
 */
final class DeletesTest extends TestCase
{
    private Container $context;
    private Database $database;
    private string $domain;

    protected function setUp(): void
    {
        global $container;

        $this->context = clone $container;
        $this->context->set('pools', fn (Registry $register) => $register->get('pools'), ['register']);
        (require __DIR__ . '/../../../app/init/worker/message.php')($this->context);

        $this->database = $this->context->get('dbForPlatform');
        $this->domain = \uniqid() . '-deletes.custom.localhost';
    }

    protected function tearDown(): void
    {
        foreach (['old', 'replacement'] as $id) {
            $this->database->deleteDocument('rules', $this->id($id));
            $this->database->deleteDocument('certificates', $this->id($id));
        }
    }

    #[DataProvider('types')]
    public function testDeleteRule(array $attributes, string $expected): void
    {
        /**
         * Test for SUCCESS
         */
        $this->database->createDocument('certificates', new Document(['$id' => $this->id('old')]));
        $rule = $this->createRule('old', $this->id('old'), $attributes);
        $this->database->deleteDocument('rules', $rule->getId());

        $this->assertSame([[$this->domain, $expected]], $this->deleteRule($rule));
        $this->assertTrue($this->database->getDocument('certificates', $this->id('old'))->isEmpty());
    }

    public static function types(): \Iterator
    {
        yield 'API' => [['type' => 'api'], ''];
        yield 'deployment' => [['type' => 'deployment', 'deploymentResourceType' => 'site'], 'site'];
    }

    public function testDeleteRecreatedRule(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->database->createDocument('certificates', new Document(['$id' => $this->id('old')]));
        $rule = $this->createRule('old', $this->id('old'));
        $this->database->deleteDocument('rules', $rule->getId());
        $this->createRule('replacement', '');

        $this->assertSame([], $this->deleteRule($rule));
        $this->assertTrue($this->database->getDocument('certificates', $this->id('old'))->isEmpty());
    }

    private function id(string $suffix): string
    {
        return \substr(\md5($this->domain), 0, 8) . '-' . $suffix;
    }

    private function createRule(string $id, string $certificateId, array $attributes = []): Document
    {
        return $this->database->createDocument('rules', new Document(\array_merge([
            '$id' => $this->id($id),
            'domain' => $this->domain,
            'certificateId' => $certificateId,
            'type' => 'api',
            'projectId' => 'console',
            'projectInternalId' => '0',
            'region' => 'default',
        ], $attributes)));
    }

    /**
     * Run the registered worker action against a deletion queued for $rule.
     *
     * @return list<array{string, ?string}> Domains the provider was asked to clean up.
     */
    private function deleteRule(Document $rule): array
    {
        $certificates = new class () implements Provider {
            /** @var list<array{string, ?string}> */
            public array $deleted = [];

            public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
            {
                return null;
            }

            public function isInstantGeneration(string $domain, ?string $domainType): bool
            {
                return false;
            }

            public function isRenewRequired(string $domain, ?string $domainType): bool
            {
                return true;
            }

            public function getCertificateStatus(string $domain, ?string $domainType): string
            {
                return Status::PENDING;
            }

            public function deleteCertificate(string $domain, ?string $domainType = null): void
            {
                $this->deleted[] = [$domain, $domainType];
            }
        };

        $message = (new Message())->setPayload((new DeleteMessage(
            type: DELETE_TYPE_DOCUMENT,
            document: $rule,
        ))->toArray());

        $this->context->set('message', fn () => $message);
        $this->context->set('certificates', fn () => $certificates);
        $this->context->set('bus', fn (Registry $register) => $register->get('bus')->setResolver($this->context->get(...)), ['register']);

        $action = new Deletes();
        ($action->getCallback())(...\array_map($this->context->get(...), $action->getInjections()));

        return $certificates->deleted;
    }
}
