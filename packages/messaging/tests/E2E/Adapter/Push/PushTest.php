<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\E2E\Adapter\Push;

use Utopia\Messaging\Adapter\Push\Mock;
use Utopia\Messaging\Messages\Push;
use Utopia\Messaging\Priority;
use Utopia\Messaging\Tests\Adapter\Base;

final class PushTest extends Base
{
    /**
     * The catcher receives every field the message carries, so a test can assert what would
     * have reached FCM or APNs.
     *
     * @throws \Exception
     */
    public function testSendPush(): void
    {
        $sender = new Mock();
        $sender->setEndpoint('http://127.0.0.1:15000/mock-push');

        $response = $sender->send(new Push(
            to: ['device-a', 'device-b'],
            title: 'Firing: Budget 100%',
            body: '$502 of the $500 budget.',
            data: ['alertId' => 'alert1', 'status' => 'firing'],
            action: 'OPEN_ALERT',
            sound: 'siren.caf',
            image: 'https://example.com/chart.png',
            icon: 'ic_alert',
            color: '#fd366e',
            tag: 'alert:alert1',
            badge: 0,
            contentAvailable: false,
            critical: true,
            priority: Priority::HIGH,
            channelId: 'alerts_critical',
            mutableContent: false,
        ));

        $request = $this->getLastRequest();

        $this->assertSame('http://127.0.0.1:15000/mock-push', $request['url']);
        $this->assertSame('POST', $request['method']);
        // Every field is forwarded under its own name; false and zero are values, not omissions.
        $this->assertSame([
            'to' => ['device-a', 'device-b'],
            'title' => 'Firing: Budget 100%',
            'body' => '$502 of the $500 budget.',
            'data' => ['alertId' => 'alert1', 'status' => 'firing'],
            'action' => 'OPEN_ALERT',
            'sound' => 'siren.caf',
            'image' => 'https://example.com/chart.png',
            'icon' => 'ic_alert',
            'color' => '#fd366e',
            'tag' => 'alert:alert1',
            'badge' => 0,
            'contentAvailable' => false,
            'critical' => true,
            'priority' => 'high',
            'channelId' => 'alerts_critical',
            'mutableContent' => false,
        ], $request['data']);

        $this->assertSame(2, $response['deliveredTo']);
        $this->assertSame('success', $response['results'][0]['status']);
        $this->assertSame('success', $response['results'][1]['status']);
    }

    /**
     * A token starting with `expired-` comes back as an expired token, the error the real
     * adapters report when FCM or APNs has dropped the device, while the rest still deliver.
     *
     * @throws \Exception
     */
    public function testExpiredTokenIsReported(): void
    {
        $sender = new Mock();
        $sender->setEndpoint('http://127.0.0.1:15000/mock-push');

        $response = $sender->send(new Push(
            to: ['device-a', Mock::EXPIRED_PREFIX . 'device-b'],
            title: 'Resolved: Egress',
        ));

        // Fields the message does not set are left out of the payload.
        $this->assertSame([
            'to' => ['device-a', Mock::EXPIRED_PREFIX . 'device-b'],
            'title' => 'Resolved: Egress',
        ], $this->getLastRequest()['data']);

        $this->assertSame(1, $response['deliveredTo']);
        $this->assertSame('device-a', $response['results'][0]['recipient']);
        $this->assertSame('success', $response['results'][0]['status']);
        $this->assertSame(Mock::EXPIRED_PREFIX . 'device-b', $response['results'][1]['recipient']);
        $this->assertSame('Expired device token', $response['results'][1]['error']);
    }
}
