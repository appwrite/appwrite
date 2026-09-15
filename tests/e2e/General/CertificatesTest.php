<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Certificate as CertificateMessage;
use Appwrite\Event\Publisher\Certificate as CertificatePublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Workers\Certificates;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\PDO;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;

/**
 * Real PostgreSQL transactions with an in-process certificate provider.
 * Each test creates and removes its own schema using the configured database.
 */
final class CertificatesTest extends TestCase
{
    private Database $database;
    private string $schema;
    private string|false $format;

    protected function setUp(): void
    {
        $this->format = getenv('_APP_RULES_FORMAT');
        if (getenv('_APP_DB_ADAPTER') !== 'postgresql' || getenv('_APP_DB_USER') === false) {
            $this->markTestSkipped('Requires the PostgreSQL adapter and configured _APP_DB_* credentials.');
        }
        putenv('_APP_RULES_FORMAT=md5');
        $this->schema = 'certificate_test_' . bin2hex(random_bytes(6));
        $this->database = $this->connect();
        $this->database->create();
        $collections = require __DIR__ . '/../../../app/config/collections/platform.php';
        foreach (['rules', 'certificates'] as $id) {
            $this->database->createCollection(
                $id,
                array_map(fn (array $attribute) => new Document($attribute), $collections[$id]['attributes']),
                array_map(fn (array $index) => new Document($index), $collections[$id]['indexes']),
            );
        }
        $this->database->createDocument('certificates', new Document([
            '$id' => 'certificate', 'domain' => 'example.com', 'attempts' => 0, 'updated' => null,
        ]));
        $this->database->createDocument('rules', new Document([
            '$id' => md5('example.com'), 'domain' => 'example.com', 'region' => 'default',
            'projectId' => 'console', 'projectInternalId' => 'console',
            'certificateId' => 'certificate', 'type' => 'api', 'status' => RULE_STATUS_CERTIFICATE_GENERATING,
        ]));
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->delete();
        }
        putenv($this->format === false ? '_APP_RULES_FORMAT' : '_APP_RULES_FORMAT=' . $this->format);
    }

    public function testClaimLocksRuleAndCertificateTogether(): void
    {
        $peer = $this->pdo();
        $checked = false;
        $this->database->on(Database::EVENT_DOCUMENT_UPDATE, 'checkLocks', function (string $event, Document $document) use ($peer, &$checked): void {
            if ($checked || $document->getCollection() !== 'certificates' || empty($document->getAttribute('updated'))) {
                return;
            }
            $checked = true;
            foreach (['rules' => md5('example.com'), 'certificates' => 'certificate'] as $collection => $id) {
                $peer->beginTransaction();
                $error = null;
                try {
                    $statement = $peer->prepare('SELECT "_uid" FROM "' . $this->schema . '"."test_' . $collection . '" WHERE "_uid" = ? FOR UPDATE NOWAIT');
                    $statement->execute([$id]);
                } catch (\PDOException $exception) {
                    $error = $exception;
                } finally {
                    $peer->rollBack();
                }
                $this->assertInstanceOf(\PDOException::class, $error, $collection . ' must remain locked until the claim commits');
                $this->assertSame('55P03', $error->getCode());
            }
        });

        $this->runWorker($this->provider());

        $this->assertTrue($checked);
        $certificate = $this->connect()->getDocument('certificates', 'certificate');
        $this->assertSame(1, $certificate->getAttribute('attempts'));
        $this->assertNull($certificate->getAttribute('updated'));
    }

    public function testFailedClaimRollsBackCertificateLease(): void
    {
        $this->database->on(Database::EVENT_DOCUMENT_UPDATE, 'failRuleWrite', static function (string $event, Document $document): void {
            if ($document->getCollection() === 'rules') {
                throw new \RuntimeException('Rule write failed');
            }
        });
        $provider = $this->createMock(Provider::class);
        $provider->expects($this->never())->method('issueCertificate');
        $error = null;
        try {
            $this->runWorker($provider);
        } catch (\RuntimeException $exception) {
            $error = $exception;
        }

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame('Rule write failed', $error->getMessage());
        $certificate = $this->connect()->getDocument('certificates', 'certificate');
        $this->assertNull($certificate->getAttribute('updated'));
        $this->assertSame(0, $certificate->getAttribute('attempts'));
        $this->assertNull($certificate->getAttribute('logs'));
    }

    public function testStaleCompletionPreservesAnotherConnectionsLease(): void
    {
        $peer = $this->connect();
        $provider = $this->provider(function () use ($peer): void {
            $peer->updateDocument('certificates', 'certificate', new Document([
                'updated' => '2099-01-01T00:00:00.000+00:00', 'attempts' => 4, 'logs' => 'Another worker',
            ]));
        });

        $this->runWorker($provider);

        $certificate = $peer->getDocument('certificates', 'certificate');
        $this->assertSame('2099-01-01T00:00:00.000+00:00', $certificate->getAttribute('updated'));
        $this->assertSame(4, $certificate->getAttribute('attempts'));
        $this->assertSame('Another worker', $certificate->getAttribute('logs'));
        $this->assertSame(RULE_STATUS_CERTIFICATE_GENERATING, $peer->getDocument('rules', md5('example.com'))->getAttribute('status'));
    }

    private function pdo(): PDO
    {
        $options = Postgres::getPDOAttributes();
        $options[\PDO::ATTR_PERSISTENT] = false;
        $dsn = 'pgsql:host=' . (getenv('_APP_DB_HOST') ?: 'postgresql')
            . ';port=' . (getenv('_APP_DB_PORT') ?: '5432')
            . ';dbname=' . (getenv('_APP_DB_SCHEMA') ?: 'postgres');
        return new PDO($dsn, getenv('_APP_DB_USER') ?: null, getenv('_APP_DB_PASS') ?: null, $options);
    }

    private function connect(): Database
    {
        $authorization = new Authorization();
        $authorization->disable();
        return (new Database(new Postgres($this->pdo()), new Cache(new NoCache())))
            ->setAuthorization($authorization)->setDatabase($this->schema)->setNamespace('test');
    }

    private function provider(?\Closure $onIssue = null): Provider
    {
        $provider = $this->createMock(Provider::class);
        $provider->method('isRenewRequired')->willReturn(true);
        $provider->method('isInstantGeneration')->willReturn(false);
        $provider->expects($this->once())->method('issueCertificate')->willReturnCallback(static function () use ($onIssue): null {
            $onIssue?->__invoke();
            return null;
        });
        return $provider;
    }

    private function runWorker(Provider $provider): void
    {
        $message = new CertificateMessage(
            project: new Document(['$id' => 'console']),
            domain: new Document(['domain' => 'example.com', 'domainType' => 'api']),
            validationDomain: 'example.com',
        );
        (new Certificates())->action(
            (new Message())->setPayload($message->toArray()),
            $this->database,
            $this->createStub(MailPublisher::class),
            $this->createStub(Event::class),
            $this->createStub(Webhook::class),
            $this->createStub(FunctionPublisher::class),
            $this->createStub(Realtime::class),
            $this->createStub(CertificatePublisher::class),
            $provider,
            [],
            new Authorization(),
            (new Bus())->setResolver(static fn () => null),
        );
    }
}
