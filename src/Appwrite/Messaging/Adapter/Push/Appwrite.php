<?php

namespace Appwrite\Messaging\Adapter\Push;

use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
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
                $this->persist($topic, $payload);
                $this->broker->send(
                    $this->projectId,
                    [],
                    [],
                    [$topic],
                    [],
                    ['payload' => $payload, 'qos' => $this->qos],
                );

                $response->incrementDeliveredTo();
                $response->addResult($topic);
            } catch (\Throwable $error) {
                $response->addResult($topic, $error->getMessage());
            }
        }

        return $response->toArray();
    }

    private function persist(string $topic, string $payload): void
    {
        $authorization = $this->dbForProject->getAuthorization();

        $sequence = $authorization->skip(
            fn () => $this->dbForProject->increaseDocumentAttribute('topics', $topic, 'sequence', 1)
        )->getAttribute('sequence');

        $authorization->skip(
            fn () => $this->dbForProject->createDocument('appwrite_push_ledger', new Document([
                '$id' => ID::unique(),
                'topic' => $topic,
                'data' => $payload,
                'messageId' => $this->messageId,
                'messageInternalId' => $this->messageInternalId,
                'sequence' => $sequence,
            ]))
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
