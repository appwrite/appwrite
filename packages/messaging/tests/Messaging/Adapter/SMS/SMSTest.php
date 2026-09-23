<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS\Mock;
use Utopia\Messaging\Messages\SMS;
use Utopia\Tests\Adapter\Base;

final class SMSTest extends Base
{
    /**
     * @throws \Exception
     */
    public function testSendSMS(): void
    {
        $sender = new Mock('username', 'password');
        $sender->setEndpoint('http://127.0.0.1:15000/mock-sms');

        $message = new SMS(
            to: ['+123456789'],
            content: 'Test Content',
            from: '+987654321',
        );

        $sender->send($message);

        $smsRequest = $this->getLastRequest();

        $this->assertEquals('http://127.0.0.1:15000/mock-sms', $smsRequest['url']);
        $this->assertEquals('Appwrite Mock Message Sender', $smsRequest['headers']['User-Agent']);
        $this->assertEquals('username', $smsRequest['headers']['X-Username']);
        $this->assertEquals('password', $smsRequest['headers']['X-Key']);
        $this->assertEquals('POST', $smsRequest['method']);
        $this->assertEquals('+987654321', $smsRequest['data']['from']);
        $this->assertEquals('+123456789', $smsRequest['data']['to']);
    }
}
