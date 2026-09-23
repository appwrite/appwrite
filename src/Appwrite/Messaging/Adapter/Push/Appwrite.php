<?php

namespace Appwrite\Messaging\Adapter\Push;

use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Messaging\Adapter\Push as PushAdapter;
use Utopia\Messaging\Messages\Push as PushMessage;
use Utopia\Messaging\Priority;
use Utopia\Messaging\Response;
use Utopia\Mqtt\Packet;
use Utopia\Telemetry\Adapter as Telemetry;

class Appwrite extends PushAdapter
{
    protected const NAME = 'Appwrite';

    // The reserved per-user topic namespace. A recipient target resolves to `users/<userId>`, an
    // implicit topic auto-provisioned on first publish so per-user pushes need no pre-created topic.
    private const USER_TOPIC_PREFIX = 'users';

    /**
     * @param Mqtt     $broker             the broker adapter used for internal pub/sub fan-out
     * @param Database $dbForProject       the project database holding the topics and ledger
     * @param string   $projectId          the project every topic and publish is scoped to
     * @param string   $messageId          source campaign message id (client-side dedup key)
     * @param ?string  $messageInternalId  source message internal id (correlation)
     * @param int      $qos                delivery QoS for the fan-out envelope
     */
    public function __construct(
        private readonly Mqtt $broker,
        private readonly Database $dbForProject,
        private readonly string $projectId,
        private readonly string $messageId,
        private readonly ?string $messageInternalId,
        private readonly int $qos = Packet::QOS_1,
        ?Telemetry $telemetry = null,
    ) {
        parent::__construct($telemetry);
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 5000;
    }

    /**
     * {@inheritdoc}
     */
    protected function process(PushMessage $message): array
    {
        $payload = $this->buildPayload($message);
        $response = new Response($this->getType());

        // A target is either a campaign topic id or a reserved users/<id> name. Publish identifies a
        // topic by id (persist/sequence/ledger) but subscribers match on the name, so resolve each
        // target to [id, name] and fan out under the name. Topic ids resolve in one bulk query;
        // reserved user topics auto-provision their row on first use.
        $names = $this->topicNames(\array_values(\array_filter(
            $message->getTo(),
            fn (string $to): bool => !$this->isUserTopic($to),
        )));

        foreach ($message->getTo() as $to) {
            try {
                [$id, $name] = $this->isUserTopic($to)
                    ? $this->createUserTopic($to)
                    : [$to, $names[$to] ?? $to];

                $sequence = $this->persist($id, $payload);
                $this->broker->send(
                    $this->projectId,
                    [],
                    [],
                    [$name],
                    [],
                    ['payload' => $payload, 'qos' => $this->qos, 'sequence' => $sequence],
                );

                $response->incrementDeliveredTo();
                $response->addResult($to);
            } catch (\Throwable $error) {
                $response->addResult($to, $error->getMessage());
            }
        }

        return $response->toArray();
    }

    /** Whether a target is a reserved per-user topic name (users/<userId>) rather than a topic id. */
    private function isUserTopic(string $to): bool
    {
        return \str_starts_with($to, self::USER_TOPIC_PREFIX . '/');
    }

    /**
     * Resolve a reserved users/<id> topic to [id, name], creating its topics row on first publish so
     * per-user pushes need no pre-created topic. The id is a deterministic hash of the reserved name
     * (as domain rules key on md5 of the domain): the primary key makes the row a singleton and needs
     * no name index, and it is length-safe and namespaced so it can't collide with a normal topic id.
     * A concurrent first publish that already created it throws Duplicate and is re-read rather than
     * duplicated. `qos` is left null (subscriber chosen), matching a normal topic.
     *
     * @return array{0: string, 1: string} [topic id, topic name]
     */
    private function createUserTopic(string $name): array
    {
        $id = ID::custom(\md5($name));
        $authorization = $this->dbForProject->getAuthorization();

        $topic = $authorization->skip(fn () => $this->dbForProject->getDocument('topics', $id));
        if ($topic->isEmpty()) {
            try {
                $authorization->skip(fn () => $this->dbForProject->createDocument('topics', new Document([
                    '$id' => $id,
                    'name' => $name,
                    'sequence' => 0,
                ])));
            } catch (Duplicate) {
                // A concurrent first publish created it; the row now exists for persist().
            }
        }

        return [$id, $name];
    }

    /**
     * Append the notification to the ledger and return its per-topic sequence. Idempotent
     * on (messageId, topic): a retry after a transient publish failure reuses the existing
     * row and sequence instead of incrementing the topic counter or inserting a duplicate.
     *
     * The increment and insert run in one transaction, opened by locking the topic row
     * (getDocument FOR UPDATE). A concurrent attempt for the same message blocks on that
     * lock, then re-reads the ledger row this transaction committed rather than racing a
     * second increment — so the topic sequence can't advance without a matching row, and
     * two attempts can't open a gap. The unique index on (messageId, topic) remains as a
     * correctness backstop.
     */
    private function persist(string $topic, string $payload): int
    {
        $authorization = $this->dbForProject->getAuthorization();

        $existing = $this->findLedger($authorization, $topic);
        if (!$existing->isEmpty()) {
            return (int) $existing->getAttribute('sequence');
        }

        return (int) $authorization->skip(
            fn () => $this->dbForProject->withTransaction(function () use ($topic, $payload, $authorization): int {
                // Lock the topic row so a concurrent attempt for the same message waits here.
                $this->dbForProject->getDocument('topics', $topic, forUpdate: true);

                // Re-check under the lock: a racing attempt may have persisted it already.
                $existing = $this->findLedger($authorization, $topic);
                if (!$existing->isEmpty()) {
                    return (int) $existing->getAttribute('sequence');
                }

                $sequence = (int) $this->dbForProject
                    ->increaseDocumentAttribute('topics', $topic, 'sequence', 1)
                    ->getAttribute('sequence');

                $this->dbForProject->createDocument('pushLedger', new Document([
                    '$id' => ID::unique(),
                    'topic' => $topic,
                    'data' => $payload,
                    'messageId' => $this->messageId,
                    'messageInternalId' => $this->messageInternalId,
                    'sequence' => $sequence,
                ]));

                return $sequence;
            })
        );
    }

    /**
     * Resolve topic ids to the names subscribers match on, in one query.
     *
     * @param  array<int, string>  $topicIds
     * @return array<string, string> id => name
     */
    private function topicNames(array $topicIds): array
    {
        if ($topicIds === []) {
            return [];
        }

        $topics = $this->dbForProject->getAuthorization()->skip(
            fn () => $this->dbForProject->find('topics', [
                Query::equal('$id', $topicIds),
                Query::select(['$id', 'name']),
                Query::limit(\count($topicIds)),
            ])
        );

        $names = [];
        foreach ($topics as $topic) {
            $names[$topic->getId()] = $topic->getAttribute('name');
        }

        return $names;
    }

    /** The existing ledger row for this campaign message on a topic, or an empty document. */
    private function findLedger(Authorization $authorization, string $topic): Document
    {
        return $authorization->skip(
            fn () => $this->dbForProject->findOne('pushLedger', [
                Query::equal('messageId', [$this->messageId]),
                Query::equal('topic', [$topic]),
            ])
        );
    }

    /**
     * Build the notification envelope delivered to devices. Mirrors the shape
     * FCM and APNS expose so SDK consumers render a consistent payload.
     */
    private function buildPayload(PushMessage $message): string
    {
        $envelope = [];

        if ($message->getTitle() !== null) {
            $envelope['notification']['title'] = $message->getTitle();
        }
        if ($message->getBody() !== null) {
            $envelope['notification']['body'] = $message->getBody();
        }
        if ($message->getImage() !== null) {
            $envelope['notification']['image'] = $message->getImage();
        }
        if ($message->getIcon() !== null) {
            $envelope['notification']['icon'] = $message->getIcon();
        }
        if ($message->getColor() !== null) {
            $envelope['notification']['color'] = $message->getColor();
        }
        if ($message->getSound() !== null) {
            $envelope['notification']['sound'] = $message->getSound();
        }
        if ($message->getTag() !== null) {
            $envelope['notification']['tag'] = $message->getTag();
        }
        if ($message->getBadge() !== null) {
            $envelope['notification']['badge'] = $message->getBadge();
        }
        if ($message->getAction() !== null) {
            $envelope['notification']['action'] = $message->getAction();
        }
        if ($message->getContentAvailable() !== null) {
            $envelope['notification']['contentAvailable'] = $message->getContentAvailable();
        }
        if ($message->getCritical() !== null) {
            $envelope['notification']['critical'] = $message->getCritical();
        }
        if ($message->getData() !== null) {
            $envelope['data'] = $message->getData();
        }
        if ($message->getPriority() instanceof Priority) {
            $envelope['priority'] = match ($message->getPriority()) {
                Priority::HIGH => 'high',
                Priority::NORMAL => 'normal',
            };
        }

        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode push payload: ' . json_last_error_msg());
        }

        return $json;
    }
}
