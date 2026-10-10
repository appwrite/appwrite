<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Proxy;

use Appwrite\Certificates\LetsEncrypt;
use Appwrite\Database\Factory;
use Appwrite\Event\Certificate as CertificateEvent;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Certificate as CertificateMessage;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Workers\Certificates;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\Unit\Event\MockPublisher;
use Throwable;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * Certificate issuance with no account email, observed on a real rule.
 *
 * The stack's worker is started with a certificate email, so these jobs are
 * applied here against the platform database. The rule API is what a caller sees.
 */
final class CertificatesCustomServerTest extends Scope
{
    use ProjectCustom;
    use ProxyHelpers;
    use SideServer;

    public function testGenerationWithoutAnEmailMarksTheRuleUnverified(): void
    {
        $domain = \uniqid('cert-email-') . '.stage-missing-cname.webapp.com';
        $created = $this->createAPIRule($domain);
        $this->assertEquals(201, $created['headers']['status-code']);
        $ruleId = $created['body']['$id'];

        $previousCertificates = \getenv('_APP_EMAIL_CERTIFICATES');
        $previousSecurity = \getenv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS');
        \putenv('_APP_EMAIL_CERTIFICATES');
        \putenv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS');

        try {
            $this->assertEquals('unverified', $created['body']['status']);

            $this->platform(function (Database $database) use ($ruleId, $domain) {
                $database->updateDocument('rules', $ruleId, new Document([
                    'status' => RULE_STATUS_CERTIFICATE_GENERATING,
                ]));

                $error = $this->generate($database, $domain);

                $this->assertInstanceOf(\Exception::class, $error);
                $this->assertSame(LetsEncrypt::EMAIL_REQUIRED, $error->getMessage());

                $stored = $database->getDocument('rules', $ruleId);
                $certificateId = $stored->getAttribute('certificateId', '');
                $this->assertIsString($certificateId);
                $this->assertNotSame('', $certificateId);
                $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATION_FAILED, $stored->getAttribute('status'));

                $certificate = $database->getDocument('certificates', $certificateId);
                $this->assertSame(5, $certificate->getAttribute('attempts'));
            });

            $rule = $this->getRule($ruleId);
            $this->assertEquals(200, $rule['headers']['status-code']);
            $this->assertEquals('unverified', $rule['body']['status']);
            $this->assertStringContainsString(LetsEncrypt::EMAIL_REQUIRED, (string) $rule['body']['logs']);
            $this->assertNotEmpty($rule['body']['renewAt']);
        } finally {
            $this->restore('_APP_EMAIL_CERTIFICATES', $previousCertificates);
            $this->restore('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', $previousSecurity);
            $this->cleanupRule($ruleId);
        }
    }

    public function testGenerationWithoutAnEmailLeavesARuleThatIsNotIssuing(): void
    {
        $domain = \uniqid('cert-skip-') . '.stage-missing-cname.webapp.com';
        $created = $this->createAPIRule($domain);
        $this->assertEquals(201, $created['headers']['status-code']);
        $ruleId = $created['body']['$id'];

        try {
            $this->assertEquals('unverified', $created['body']['status']);
            $this->assertNotEmpty($created['body']['logs']);

            $this->platform(function (Database $database) use ($ruleId, $domain) {
                $error = $this->generate($database, $domain);

                $this->assertNull($error);

                $stored = $database->getDocument('rules', $ruleId);
                $this->assertSame(RULE_STATUS_CREATED, $stored->getAttribute('status'));
                $this->assertEmpty($stored->getAttribute('certificateId', ''));
            });

            $rule = $this->getRule($ruleId);
            $this->assertEquals(200, $rule['headers']['status-code']);
            $this->assertEquals('unverified', $rule['body']['status']);
            $this->assertEquals($created['body']['logs'], $rule['body']['logs']);
        } finally {
            $this->cleanupRule($ruleId);
        }
    }

    /**
     * @param callable(Database): mixed $callback
     */
    private function platform(callable $callback): mixed
    {
        $run = function () use ($callback) {
            global $register;

            $pools = $register->get('pools');
            $cache = new Cache(new Sharding(\array_map(
                fn (string $name) => new CachePool($pools->get($name)),
                Config::getParam('pools-cache', []),
            )));
            $authorization = new Authorization();
            $authorization->disable();
            $database = (new Factory($pools, $cache, $authorization))->platform();

            return $authorization->skip(fn () => $callback($database));
        };

        if (\Swoole\Coroutine::getCid() >= 0) {
            return $run();
        }

        $result = null;
        $failure = null;
        \Swoole\Coroutine\run(function () use ($run, &$result, &$failure) {
            try {
                $result = $run();
            } catch (Throwable $caught) {
                $failure = $caught;
            }
        });

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        return $result;
    }

    private function generate(Database $database, string $domain): ?Throwable
    {
        global $container, $register;

        $mail = new MockPublisher();
        $message = (new Message())->setPayload((new CertificateMessage(
            project: new Document([
                '$id' => $this->getProject()['$id'],
                '$sequence' => '1',
            ]),
            domain: new Document([
                'domain' => $domain,
                'domainType' => 'api',
            ]),
            action: CertificateEvent::ACTION_GENERATION,
            skipDomainValidation: true,
        ))->toArray());

        $error = null;

        try {
            (new Certificates())->action(
                $message,
                $database,
                new MailPublisher($mail, new Queue('v1-mails')),
                new Event($container->get('publisher')),
                new Webhook($container->get('publisher')),
                $container->get('publisherForFunctions'),
                new Realtime(),
                $container->get('publisherForCertificates'),
                new LetsEncrypt(''),
                [],
                new Authorization(),
                $register->get('bus')->setResolver(static fn (string $name) => $container->get($name)),
            );
        } catch (Throwable $caught) {
            $error = $caught;
        }

        $this->assertNull($mail->getEvents('v1-mails'));

        return $error;
    }

    private function restore(string $name, string|false $value): void
    {
        if ($value === false) {
            \putenv($name);

            return;
        }

        \putenv($name . '=' . $value);
    }
}
