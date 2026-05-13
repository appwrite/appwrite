<?php

namespace Appwrite\Realtime\Message\Handlers;

use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Realtime\Message\Dispatcher;
use Appwrite\Realtime\Message\Validators\UnsubscribePayload as UnsubscribePayloadValidator;
use Utopia\Platform\Action;
use Utopia\Registry\Registry;
use Utopia\Span\Span;

class Unsubscribe extends Action
{
    public function __construct()
    {
        $this
            ->desc('Bulk remove subscriptions by id')
            ->label(Dispatcher::LABEL_MESSAGE_TYPE, 'unsubscribe')
            ->label(Dispatcher::LABEL_PAYLOAD_SHAPE, Dispatcher::PAYLOAD_SHAPE_LIST)
            ->param('items', null, fn () => new UnsubscribePayloadValidator(), 'Subscriptions to remove')
            ->inject('connectionId')
            ->inject('realtime')
            ->inject('register')
            ->callback($this->action(...));
    }

    /**
     * @param array<int, array{subscriptionId: string}> $items
     * @return array<string, mixed>
     */
    public function action(
        array $items,
        int $connectionId,
        Realtime $realtime,
        Registry $register,
    ): array {
        $subscriptionsBefore = \count($realtime->getSubscriptionMetadata($connectionId));

        $unsubscribeResults = [];
        foreach ($items as $payload) {
            $subscriptionId = $payload['subscriptionId'];
            $unsubscribeResults[] = [
                'subscriptionId' => $subscriptionId,
                'removed' => $realtime->unsubscribeSubscription($connectionId, $subscriptionId),
            ];
        }

        $subscriptionsAfter = \count($realtime->getSubscriptionMetadata($connectionId));
        $subscriptionDelta = $subscriptionsAfter - $subscriptionsBefore;
        $subscriptionsRequested = \count($items);
        $subscriptionsRemoved = \count(\array_filter(
            $unsubscribeResults,
            static fn (array $item): bool => $item['removed']
        ));

        if ($subscriptionDelta !== 0) {
            $register->get('telemetry.workerSubscriptionCounter')
                ->add($subscriptionDelta, $register->get('telemetry.workerAttributes'));
        }

        Span::add('realtime.subscription_delta', $subscriptionDelta);
        Span::add('realtime.subscriptions_requested', $subscriptionsRequested);
        Span::add('realtime.subscriptions_removed', $subscriptionsRemoved);

        return [
            'type' => 'response',
            'data' => [
                'to' => 'unsubscribe',
                'success' => true,
                'subscriptions' => $unsubscribeResults,
            ],
        ];
    }
}
