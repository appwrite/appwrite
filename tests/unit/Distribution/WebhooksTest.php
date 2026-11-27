<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Distribution\Fakes\RecordingApple;
use Tests\Unit\Distribution\Fakes\ScriptedStore;
use Tests\Unit\Distribution\Fakes\ScriptedStores;
use Tests\Unit\Distribution\Fakes\SubmissionDatabase;
use Utopia\Database\Document;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Store\Apple\Webhook;

final class WebhooksTest extends TestCase
{
    private const array ENVIRONMENT = ['_APP_VCS_WEBHOOK_URL', '_APP_DOMAIN', '_APP_OPTIONS_FORCE_HTTPS'];

    private const string URL = 'https://appwrite.test/v1/distribution/webhooks/apple/connection/target?project=project';

    private static string $privateKey;

    private SubmissionDatabase $database;

    private RecordingApple $store;

    private Document $project;

    private Document $connection;

    private Document $target;

    /**
     * @var array<string, string|false>
     */
    private array $environment = [];

    public static function setUpBeforeClass(): void
    {
        $key = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        self::assertTrue(\openssl_pkey_export($key, $privateKey));

        self::$privateKey = $privateKey;
    }

    #[\Override]
    protected function setUp(): void
    {
        foreach (self::ENVIRONMENT as $name) {
            $this->environment[$name] = \getenv($name);
        }

        \putenv('_APP_VCS_WEBHOOK_URL=https://appwrite.test/v1');

        $this->database = new SubmissionDatabase();
        $this->store = new RecordingApple(self::$privateKey);
        $this->project = new Document(['$id' => 'project', '$sequence' => '7']);
        $this->connection = $this->connection();
        $this->target = $this->database->seed('distributionTargets', [
            '$id' => 'target',
            'platform' => 'ios',
            'identifier' => 'io.example.app',
            'connectionId' => 'connection',
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            \putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testFirstRegisterCreatesTheWebhookAndStoresItsSecret(): void
    {
        $this->webhooks()->register($this->project, $this->connection, $this->target, 'store-app');

        $entry = $this->entry();
        $this->assertSame(['listWebhooks', 'createWebhook'], $this->store->calls);
        $this->assertCount(1, $this->store->created);
        $this->assertSame('store-app', $this->store->created[0]['appId']);
        $this->assertSame(self::URL, $this->store->created[0]['url']);
        $this->assertSame(Webhook::EVENTS, $this->store->created[0]['events']);
        $this->assertSame($this->store->created[0]['secret'], $entry['secret']);
        $this->assertSame(64, \strlen($entry['secret']));
        $this->assertTrue(\ctype_xdigit($entry['secret']));
        $this->assertSame('webhook-1', $entry['id']);
        $this->assertSame('store-app', $entry['appId']);
        $this->assertSame('PRIVATE-KEY', $this->stored()->getAttribute('credentials')['privateKey']);
    }

    public function testRegisteredTargetIsLeftAlone(): void
    {
        $webhooks = $this->webhooks();
        $webhooks->register($this->project, $this->connection, $this->target, 'store-app');
        $writes = \count($this->database->writes('distributionConnections'));

        $webhooks->register($this->project, $this->stored(), $this->target, 'store-app');

        $this->assertSame(['listWebhooks', 'createWebhook'], $this->store->calls);
        $this->assertCount($writes, $this->database->writes('distributionConnections'));
    }

    public function testEntryWithoutIdReplacesEverySameUrlWebhook(): void
    {
        $this->store->webhooks['store-app'] = [
            new Webhook('first', Webhook::NAME, self::URL, Webhook::EVENTS, true),
            new Webhook('second', Webhook::NAME, self::URL, Webhook::EVENTS, true),
        ];
        $connection = $this->connection(['target' => ['appId' => 'store-app', 'secret' => 'kept-secret']]);

        $this->webhooks()->register($this->project, $connection, $this->target, 'store-app');

        $this->assertSame(['first', 'second'], $this->store->deleted);
        $this->assertCount(1, $this->store->created);
        $this->assertSame('kept-secret', $this->store->created[0]['secret']);
        $this->assertSame(['webhook-1'], \array_map(static fn (Webhook $webhook): string => $webhook->id, $this->store->webhooks['store-app']));
        $this->assertSame(['id' => 'webhook-1', 'appId' => 'store-app', 'secret' => 'kept-secret'], $this->entry());
    }

    public function testFailedDeleteIsRetriedWithTheStoredSecret(): void
    {
        $this->store->webhooks['store-app'] = [new Webhook('stale', Webhook::NAME, self::URL, Webhook::EVENTS, true)];
        $this->store->deleteFailure = new Unauthorized('Transient refusal');
        $webhooks = $this->webhooks();

        $webhooks->register($this->project, $this->connection, $this->target, 'store-app');
        $secret = $this->entry()['secret'];
        $webhooks->register($this->project, $this->stored(), $this->target, 'store-app');

        $this->assertSame(['stale'], $this->store->deleted);
        $this->assertCount(1, $this->store->created);
        $this->assertSame($secret, $this->store->created[0]['secret']);
        $this->assertSame(['webhook-1'], \array_map(static fn (Webhook $webhook): string => $webhook->id, $this->store->webhooks['store-app']));
        $this->assertSame(['id' => 'webhook-1', 'appId' => 'store-app', 'secret' => $secret], $this->entry());
    }

    public function testEntryWithoutIdAndNoWebhookCreatesOneWithItsSecret(): void
    {
        $connection = $this->connection(['target' => ['appId' => 'store-app', 'secret' => 'kept-secret']]);

        $this->webhooks()->register($this->project, $connection, $this->target, 'store-app');

        $this->assertSame(['listWebhooks', 'createWebhook'], $this->store->calls);
        $this->assertSame('kept-secret', $this->store->created[0]['secret']);
        $this->assertSame(['id' => 'webhook-1', 'appId' => 'store-app', 'secret' => 'kept-secret'], $this->entry());
    }

    public function testWebhookWithAnUnknownSecretIsReplaced(): void
    {
        $this->store->webhooks['store-app'] = [
            new Webhook('stale', Webhook::NAME, self::URL, Webhook::EVENTS, true),
            new Webhook('foreign', 'Other', 'https://example.test/hook', Webhook::EVENTS, true),
        ];

        $this->webhooks()->register($this->project, $this->connection, $this->target, 'store-app');

        $this->assertSame(['listWebhooks', 'deleteWebhook', 'createWebhook'], $this->store->calls);
        $this->assertSame(['stale'], $this->store->deleted);
        $this->assertSame('webhook-1', $this->entry()['id']);
        $this->assertSame($this->store->created[0]['secret'], $this->entry()['secret']);
    }

    public function testChangedAppRegistersAgain(): void
    {
        $connection = $this->connection(['target' => ['id' => 'old-webhook', 'appId' => 'old-app', 'secret' => 'old-secret']]);

        $this->webhooks()->register($this->project, $connection, $this->target, 'store-app');

        $entry = $this->entry();
        $this->assertSame(['listWebhooks', 'createWebhook'], $this->store->calls);
        $this->assertSame('store-app', $this->store->created[0]['appId']);
        $this->assertSame('store-app', $entry['appId']);
        $this->assertSame('webhook-1', $entry['id']);
        $this->assertNotSame('old-secret', $entry['secret']);
    }

    public function testRefusedRegistrationIsSwallowed(): void
    {
        $this->store->failure = new Unauthorized('A Developer key cannot manage webhooks');

        $this->webhooks()->register($this->project, $this->connection, $this->target, 'store-app');

        $entry = $this->entry();
        $this->assertArrayNotHasKey('id', $entry);
        $this->assertSame('store-app', $entry['appId']);
        $this->assertSame(64, \strlen($entry['secret']));
    }

    public function testOtherStoresAreNotRegistered(): void
    {
        $webhooks = new Webhooks($this->database, new ScriptedStores(new ScriptedStore()));

        $webhooks->register($this->project, $this->connection, $this->target, 'store-app');

        $this->assertSame([], $this->database->writes('distributionConnections'));
    }

    public function testKeyRotatedAfterTheConnectionWasLoadedIsKept(): void
    {
        $loaded = $this->connection;
        $this->database->updateDocument('distributionConnections', 'connection', new Document([
            'credentials' => [...$loaded->getAttribute('credentials'), 'privateKey' => 'ROTATED-KEY'],
        ]));

        $this->webhooks()->register($this->project, $loaded, $this->target, 'store-app');

        $credentials = $this->stored()->getAttribute('credentials');
        $this->assertSame('ROTATED-KEY', $credentials['privateKey']);
        $this->assertSame('webhook-1', $credentials['webhooks']['target']['id']);
    }

    public function testOtherTargetsKeepTheirEntries(): void
    {
        $this->database->seed('distributionTargets', ['$id' => 'other', 'platform' => 'ios', 'connectionId' => 'connection', 'connectionInternalId' => '3']);
        $other = ['id' => 'other-webhook', 'appId' => 'other-app', 'secret' => 'other-secret'];
        $connection = $this->connection(['other' => $other]);

        $this->webhooks()->register($this->project, $connection, $this->target, 'store-app');

        $this->assertSame($other, $this->stored()->getAttribute('credentials')['webhooks']['other']);
        $this->assertSame('webhook-1', $this->entry()['id']);
    }

    public function testStaleEntriesArePrunedOnRecord(): void
    {
        $this->database->seed('distributionTargets', ['$id' => 'live', 'platform' => 'ios', 'connectionId' => 'connection', 'connectionInternalId' => '3']);
        $this->database->seed('distributionTargets', ['$id' => 'moved', 'platform' => 'ios', 'connectionId' => 'elsewhere', 'connectionInternalId' => '9']);
        $entry = ['id' => 'webhook', 'appId' => 'app', 'secret' => 'secret'];
        $connection = $this->connection(['live' => $entry, 'moved' => $entry, 'deleted' => $entry]);

        $this->webhooks()->register($this->project, $connection, $this->target, 'store-app');

        $webhooks = $this->stored()->getAttribute('credentials')['webhooks'];
        $this->assertSame(['live', 'target'], \array_keys($webhooks));
        $this->assertSame($entry, $webhooks['live']);
    }

    public function testUnregisterDropsOnlyThatTargetsEntryAndDeletesItsWebhook(): void
    {
        $other = ['id' => 'other-webhook', 'appId' => 'other-app', 'secret' => 'other-secret'];
        $this->connection([
            'target' => ['id' => 'target-webhook', 'appId' => 'store-app', 'secret' => 'target-secret'],
            'other' => $other,
        ]);

        $this->webhooks()->unregister($this->target);

        $credentials = $this->stored()->getAttribute('credentials');
        $this->assertSame(['other' => $other], $credentials['webhooks']);
        $this->assertSame('PRIVATE-KEY', $credentials['privateKey']);
        $this->assertSame(['target-webhook'], $this->store->deleted);
    }

    public function testUnregisterKeepsTheRemovalWhenTheStoreRefuses(): void
    {
        $this->connection(['target' => ['id' => 'target-webhook', 'appId' => 'store-app', 'secret' => 'target-secret']]);
        $this->store->deleteFailure = new Unauthorized('A Developer key cannot manage webhooks');

        $this->webhooks()->unregister($this->target);

        $this->assertSame([], $this->stored()->getAttribute('credentials')['webhooks']);
        $this->assertSame(['deleteWebhook'], $this->store->calls);
    }

    public function testUnregisterWithoutEntryChangesNothing(): void
    {
        $this->webhooks()->unregister($this->target);

        $this->assertSame([], $this->database->writes('distributionConnections'));
        $this->assertSame([], $this->store->calls);
    }

    /**
     * @return \Iterator<string, array{array<string, string|null>, string}>
     */
    public static function urls(): \Iterator
    {
        yield 'webhook url with version' => [['_APP_VCS_WEBHOOK_URL' => 'https://hooks.test/v1'], 'https://hooks.test'];
        yield 'webhook url without version' => [['_APP_VCS_WEBHOOK_URL' => 'https://hooks.test'], 'https://hooks.test'];
        yield 'webhook url with trailing slash' => [['_APP_VCS_WEBHOOK_URL' => 'https://hooks.test/v1/'], 'https://hooks.test'];
        yield 'domain over https' => [['_APP_VCS_WEBHOOK_URL' => null, '_APP_DOMAIN' => 'cloud.test', '_APP_OPTIONS_FORCE_HTTPS' => 'enabled'], 'https://cloud.test'];
        yield 'domain over http' => [['_APP_VCS_WEBHOOK_URL' => null, '_APP_DOMAIN' => 'cloud.test', '_APP_OPTIONS_FORCE_HTTPS' => 'disabled'], 'http://cloud.test'];
        yield 'default domain' => [['_APP_VCS_WEBHOOK_URL' => null, '_APP_DOMAIN' => null, '_APP_OPTIONS_FORCE_HTTPS' => null], 'https://localhost'];
    }

    /**
     * @param array<string, string|null> $environment
     */
    #[DataProvider('urls')]
    public function testUrl(array $environment, string $base): void
    {
        foreach ($environment as $name => $value) {
            \putenv($value === null ? $name : "{$name}={$value}");
        }

        $url = Webhooks::url($this->project, $this->connection, $this->target);

        $this->assertSame($base . '/v1/distribution/webhooks/apple/connection/target?project=project', $url);
    }

    private function webhooks(): Webhooks
    {
        return new Webhooks($this->database, new ScriptedStores($this->store));
    }

    /**
     * @param array<string, array<string, string>> $webhooks
     */
    private function connection(array $webhooks = []): Document
    {
        $credentials = ['keyId' => 'KEY1234567', 'issuerId' => 'issuer-1', 'privateKey' => 'PRIVATE-KEY'];

        if ($webhooks !== []) {
            $credentials['webhooks'] = $webhooks;
        }

        return $this->database->seed('distributionConnections', [
            '$id' => 'connection',
            '$sequence' => '3',
            'provider' => 'apple',
            'credentials' => $credentials,
        ]);
    }

    private function stored(): Document
    {
        return $this->database->getDocument('distributionConnections', 'connection');
    }

    /**
     * @return array<string, string>
     */
    private function entry(): array
    {
        return $this->stored()->getAttribute('credentials')['webhooks']['target'] ?? [];
    }
}
