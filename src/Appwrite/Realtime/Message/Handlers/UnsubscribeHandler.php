<?php

namespace Appwrite\Realtime\Message\Handlers;

use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Realtime\Message\Dispatcher;
use Appwrite\Realtime\Message\Validators\UnsubscribePayloadValidator;
use Utopia\Platform\Action;
use Utopia\Registry\Registry;
use Utopia\Span\Span;

class UnsubscribeHandler extends Action
{
    public function __construct()
    {
        $this
            ->desc('Bulk remove subscriptions by id')
            ->label(Dispatcher::LABEL_MESSAGE_TYPE, 'unsubscribe')
            ->label(Dispatcher::LABEL_PAYLOAD_SHAPE, Dispatcher::PAYLOAD_SHAPE_LIST)
            ->param('items', null, new UnsubscribePayloadValidator(), 'Subscriptions to remove')
            ->inject('connection')
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
        int $connection,
        Realtime $realtime,
        Registry $register,
    ): array {
        $subscriptionsBefore = \count($realtime->getSubscriptionMetadata($connection));

        $unsubscribeResults = [];
        foreach ($items as $payload) {
            $subscriptionId = $payload['subscriptionId'];
            $unsubscribeResults[] = [
                'subscriptionId' => $subscriptionId,
                'removed' => $realtime->unsubscribeSubscription($connection, $subscriptionId),
            ];
        }

        $subscriptionsAfter = \count($realtime->getSubscriptionMetadata($connection));
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

        Span::add('realtime.subscriptionDelta', $subscriptionDelta);
        Span::add('realtime.subscriptionsRequested', $subscriptionsRequested);
        Span::add('realtime.subscriptionsRemoved', $subscriptionsRemoved);

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
