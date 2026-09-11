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

        foreach ($message->getTo() as $topic) {
            try {
                $sequence = $this->persist($topic, $payload);
                $this->broker->send(
                    $this->projectId,
                    [],
                    [],
                    [$topic],
                    [],
                    ['payload' => $payload, 'qos' => $this->qos, 'sequence' => $sequence],
                );

                $response->incrementDeliveredTo();
                $response->addResult($topic);
            } catch (\Throwable $error) {
                $response->addResult($topic, $error->getMessage());
            }
        }

        return $response->toArray();
    }

    /**
     * Append the notification to the ledger and return its per-topic sequence. Idempotent
     * on (messageId, topic): a retry after a transient publish failure reuses the existing
     * row and sequence instead of incrementing the topic counter or inserting a duplicate.
     *
     * The increment and insert run in one transaction so a failed insert rolls the
     * increment back — the topic sequence never advances without a matching ledger row.
     * The unique index on (messageId, topic) also serialises two concurrent attempts:
     * the loser's insert throws Duplicate, its increment rolls back, and it resolves to
     * the winner's sequence instead of opening a gap.
     */
    private function persist(string $topic, string $payload): int
    {
        $authorization = $this->dbForProject->getAuthorization();

        $existing = $this->findLedger($authorization, $topic);
        if (!$existing->isEmpty()) {
            return (int) $existing->getAttribute('sequence');
        }

        try {
            return (int) $authorization->skip(
                fn () => $this->dbForProject->withTransaction(function () use ($topic, $payload): int {
                    $sequence = (int) $this->dbForProject
                        ->increaseDocumentAttribute('topics', $topic, 'sequence', 1)
                        ->getAttribute('sequence');

                    $this->dbForProject->createDocument('appwritePushLedger', new Document([
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
        } catch (Duplicate) {
            // A concurrent attempt won the (messageId, topic) unique index; its row and
            // increment are committed. Return that sequence rather than incrementing again.
            return (int) $this->findLedger($authorization, $topic)->getAttribute('sequence');
        }
    }

    /** The existing ledger row for this campaign message on a topic, or an empty document. */
    private function findLedger(Authorization $authorization, string $topic): Document
    {
        return $authorization->skip(
            fn () => $this->dbForProject->findOne('appwritePushLedger', [
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
