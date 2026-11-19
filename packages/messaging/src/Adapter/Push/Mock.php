<?php

namespace Utopia\Messaging\Adapter\Push;

use Utopia\Client\Destinations;
use Utopia\Client\Destinations\Anywhere;
use Utopia\Messaging\Adapter\Push as PushAdapter;
use Utopia\Messaging\Messages\Push as PushMessage;
use Utopia\Messaging\Priority;
use Utopia\Messaging\Response;

/**
 * A push adapter for tests: posts the whole message to a request catcher instead of FCM or APNs,
 * so a test can read back exactly what would have been pushed.
 *
 * A device token starting with `expired-` is reported as an expired token, so the caller's
 * expiry handling can be driven without a real push service.
 */
class Mock extends PushAdapter
{
    protected const NAME = 'Mock';

    public const EXPIRED_PREFIX = 'expired-';

    protected string $url = 'http://request-catcher:5000/mock-push';

    public function getName(): string
    {
        return static::NAME;
    }

    public function getEndpoint(): string
    {
        return $this->url;
    }

    public function setEndpoint(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * A test double the operator points at a local request catcher.
     */
    protected function destinations(): Destinations
    {
        return new Anywhere();
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 1000;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    protected function process(PushMessage $message): array
    {
        $response = new Response($this->getType());

        $result = $this->request(
            method: 'POST',
            url: $this->url,
            headers: ['Content-Type: application/json'],
            body: \array_filter([
                'to' => $message->getTo(),
                'title' => $message->getTitle(),
                'body' => $message->getBody(),
                'data' => $message->getData(),
                'action' => $message->getAction(),
                'sound' => $message->getSound(),
                'image' => $message->getImage(),
                'icon' => $message->getIcon(),
                'color' => $message->getColor(),
                'tag' => $message->getTag(),
                'badge' => $message->getBadge(),
                'contentAvailable' => $message->getContentAvailable(),
                'critical' => $message->getCritical(),
                'priority' => match ($message->getPriority()) {
                    Priority::HIGH => 'high',
                    Priority::NORMAL => 'normal',
                    null => null,
                },
                'channelId' => $message->getChannelId(),
                'mutableContent' => $message->getMutableContent(),
            ], fn (mixed $value) => $value !== null),
        );

        foreach ($message->getTo() as $to) {
            if (\str_starts_with($to, self::EXPIRED_PREFIX)) {
                $response->addResult($to, $this->getExpiredErrorMessage());
                continue;
            }

            if ($result['statusCode'] === 200) {
                $response->incrementDeliveredTo();
                $response->addResult($to);
                continue;
            }

            $response->addResult($to, $result['error'] ?: 'Unknown Error.');
        }

        return $response->toArray();
    }
}
