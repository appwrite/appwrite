<?php

declare(strict_types=1);

namespace Tests\Unit\Deletes;

use Appwrite\Deletes\Targets;
use Appwrite\Messaging\Adapter\Mqtt;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Appwrite\Messaging\Adapter\Fakes\RecordingPubSub;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

require_once __DIR__ . '/../../../app/init.php';

/**
 * The broker never re-checks topic membership while a connection stays open (see
 * Appwrite\Mqtt\Handler::deliver()), so every cleanup path that removes a subscriber row
 * must notify it directly, the same way the explicit subscriber-delete endpoint does, or
 * an already-open connection keeps receiving a topic it no longer has access to.
 */
final class TargetsTest extends TestCase
{
    private Database $database;

    private RecordingPubSub $pubsub;

    protected function setUp(): void
    {
        $authorization = new Authorization();
        $authorization->disable();

        $this->database = (new Database(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('_console')
            ->setAuthorization($authorization);

        $this->database->create();

        $collections = Config::getParam('collections', []);
        foreach (['topics', 'subscribers', 'targets'] as $id) {
            $this->database->createCollection(Collection::create(
                id: $id,
                attributes: $collections['projects'][$id]['attributes'],
                indexes: $collections['projects'][$id]['indexes'],
            ));
        }

        $this->pubsub = new RecordingPubSub();
    }

    private function mqtt(): Mqtt
    {
        return new Mqtt(new NoTelemetry(), $this->pubsub);
    }

    /** @return array{0: Document, 1: Document} [target, subscriber] */
    private function subscribe(Document $topic, string $userId, string $targetSuffix, string $providerType = MESSAGE_TYPE_PUSH): array
    {
        $target = $this->database->createDocument('targets', new Document([
            '$id' => 'target-' . $userId . '-' . $targetSuffix,
            'userId' => $userId,
            'userInternalId' => $userId,
            'providerType' => $providerType,
            'identifier' => 'identifier-' . $userId . '-' . $targetSuffix,
        ]));

        $subscriber = $this->database->createDocument('subscribers', new Document([
            '$id' => 'subscriber-' . $userId . '-' . $targetSuffix,
            'targetId' => $target->getId(),
            'targetInternalId' => $target->getSequence(),
            'userId' => $userId,
            'userInternalId' => $userId,
            'topicId' => $topic->getId(),
            'topicInternalId' => $topic->getSequence(),
            'providerType' => $providerType,
        ]));

        return [$target, $subscriber];
    }

    private function createTopic(): Document
    {
        return $this->database->createDocument('topics', new Document([
            '$id' => 'topic-1',
            'name' => 'Topic 1',
            // Matches the subscriber rows seeded below, so the unrelated pushTotal
            // decrement this path also does doesn't hit its floor and log noise.
            'pushTotal' => 2,
        ]));
    }

    public function testRevokesTheBrokerSubscriptionOnceTheUsersLastRowForTheTopicIsGone(): void
    {
        $topic = $this->createTopic();
        [$target] = $this->subscribe($topic, 'user-1', 'push');

        Targets::deleteSubscribers($this->database, $target, $this->mqtt(), 'project-1');

        $this->assertCount(1, $this->pubsub->published);
        $event = \json_decode($this->pubsub->published[0]['message'], true);
        $this->assertSame(Mqtt::ACTION_UNSUBSCRIBE, $event['action']);
        $this->assertSame('project-1', $event['project']);
        $this->assertSame('user-1', $event['userId']);
        $this->assertSame('topic-1', $event['topic']);
    }

    public function testDoesNotRevokeWhileAnotherSubscriberRowForTheSameUserAndTopicRemains(): void
    {
        $topic = $this->createTopic();
        [$targetA] = $this->subscribe($topic, 'user-1', 'push-a');
        $this->subscribe($topic, 'user-1', 'push-b');

        Targets::deleteSubscribers($this->database, $targetA, $this->mqtt(), 'project-1');

        $this->assertSame([], $this->pubsub->published);
    }

    public function testDoesNotRevokeAnotherUsersSubscription(): void
    {
        $topic = $this->createTopic();
        [$targetA] = $this->subscribe($topic, 'user-1', 'push');
        $this->subscribe($topic, 'user-2', 'push');

        Targets::deleteSubscribers($this->database, $targetA, $this->mqtt(), 'project-1');

        $this->assertCount(1, $this->pubsub->published);
        $event = \json_decode($this->pubsub->published[0]['message'], true);
        $this->assertSame('user-1', $event['userId']);
    }

    public function testDoesNotRevokeForAnEmailOrSmsTargetEvenAsTheLastRow(): void
    {
        $topic = $this->createTopic();
        $topic = $this->database->updateDocument('topics', $topic->getId(), $topic->setAttribute('emailTotal', 1));
        [$target] = $this->subscribe($topic, 'user-1', 'email', MESSAGE_TYPE_EMAIL);

        Targets::deleteSubscribers($this->database, $target, $this->mqtt(), 'project-1');

        $this->assertSame([], $this->pubsub->published, 'MQTT only delivers to push targets; an email target has nothing for a broker to revoke.');
    }

    public function testWithoutAnMqttAdapterDeletionStillSucceeds(): void
    {
        $topic = $this->createTopic();
        [$target] = $this->subscribe($topic, 'user-1', 'push');

        Targets::deleteSubscribers($this->database, $target);

        $this->assertSame([], $this->database->find('subscribers', [
            Query::equal('targetInternalId', [$target->getSequence()]),
        ]));
    }
}
