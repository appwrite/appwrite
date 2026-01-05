<?php

namespace Tests\E2E\Services\Messaging;

use Appwrite\Messaging\Status as MessageStatus;
use Tests\E2E\Client;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;

/**
 * Test for Issue #8226: SMS Scheduling Time Validation with Minute Precision
 * 
 * This test verifies that the DatetimeValidator accepts scheduled times with minute precision
 * and properly rounds times to the nearest minute, without requiring exact second matching.
 */
trait MessagingSchedulingPrecisionTest
{
    /**
     * Test scheduling SMS with minute precision (issue #8226)
     * 
     * Tests that scheduled times are validated with minute-level precision,
     * allowing arbitrary seconds to be rounded to the nearest minute.
     */
    public function testScheduledMessageMinutePrecision(): void
    {
        // Create user for testing
        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . "@example.com",
            'password' => 'password',
            'name' => 'Messaging Test User',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);
        $targetId = $user['body']['targets'][0]['$id'];

        // Test 1: Schedule SMS with on-the-minute time (e.g., 15:30:00)
        // This should work in both old and new implementations
        $scheduledTime1 = DateTime::addSeconds(new \DateTime(), 120); // 2 minutes from now
        $scheduledTime1Rounded = (new \DateTime($scheduledTime1))->format('Y-m-d\TH:i:00.000\Z');

        $sms1 = $this->client->call(Client::METHOD_POST, '/messaging/messages/sms', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'content' => 'Test SMS with exact minute',
            'targets' => [$targetId],
            'scheduledAt' => $scheduledTime1Rounded,
        ]);

        $this->assertEquals(201, $sms1['headers']['status-code'], 'Failed to create SMS with on-the-minute time');
        $this->assertEquals(MessageStatus::SCHEDULED, $sms1['body']['status']);

        // Test 2: Schedule SMS with arbitrary seconds (e.g., 15:30:37)
        // This is the CRITICAL test for the fix - should round to nearest minute
        $futureTime = new \DateTime();
        $futureTime->add(new \DateInterval('PT3M')); // 3 minutes from now
        $futureTime->setTime(
            (int) $futureTime->format('H'),
            (int) $futureTime->format('i'),
            37 // Arbitrary seconds
        );

        $sms2 = $this->client->call(Client::METHOD_POST, '/messaging/messages/sms', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'content' => 'Test SMS with arbitrary seconds',
            'targets' => [$targetId],
            'scheduledAt' => $futureTime->format('Y-m-d\TH:i:s.000\Z'),
        ]);

        $this->assertEquals(201, $sms2['headers']['status-code'], 'Failed to create SMS with arbitrary seconds');
        $this->assertEquals(MessageStatus::SCHEDULED, $sms2['body']['status']);

        // Test 3: Schedule Email with arbitrary seconds
        $futureTime2 = new \DateTime();
        $futureTime2->add(new \DateInterval('PT4M')); // 4 minutes from now
        $futureTime2->setTime(
            (int) $futureTime2->format('H'),
            (int) $futureTime2->format('i'),
            12 // Another arbitrary second value
        );

        $email = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'subject' => 'Test Email',
            'content' => 'Test email with arbitrary seconds',
            'targets' => [$targetId],
            'scheduledAt' => $futureTime2->format('Y-m-d\TH:i:s.000\Z'),
        ]);

        $this->assertEquals(201, $email['headers']['status-code'], 'Failed to create email with arbitrary seconds');
        $this->assertEquals(MessageStatus::SCHEDULED, $email['body']['status']);

        // Test 4: Update scheduled message with arbitrary seconds
        $futureTime3 = new \DateTime();
        $futureTime3->add(new \DateInterval('PT5M')); // 5 minutes from now
        $futureTime3->setTime(
            (int) $futureTime3->format('H'),
            (int) $futureTime3->format('i'),
            45 // Yet another arbitrary second value
        );

        $updatedEmail = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $email['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'scheduledAt' => $futureTime3->format('Y-m-d\TH:i:s.000\Z'),
        ]);

        $this->assertEquals(200, $updatedEmail['headers']['status-code'], 'Failed to update email with arbitrary seconds');
        $this->assertEquals(MessageStatus::SCHEDULED, $updatedEmail['body']['status']);

        // Test 5: Verify that times less than 1 minute in the future are rejected
        $tooSoonTime = DateTime::addSeconds(new \DateTime(), 30); // Only 30 seconds in future

        $sms3 = $this->client->call(Client::METHOD_POST, '/messaging/messages/sms', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'content' => 'Test SMS too soon',
            'targets' => [$targetId],
            'scheduledAt' => $tooSoonTime,
        ]);

        $this->assertEquals(400, $sms3['headers']['status-code'], 'Should reject time less than 1 minute in future');

        // Test 6: Schedule Push Notification with arbitrary seconds
        // First create a push target
        $pushTarget = $this->client->call(Client::METHOD_POST, '/users/' . $user['body']['$id'] . '/targets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'targetId' => ID::unique(),
            'providerType' => 'push',
            'identifier' => 'test-device-token',
        ]);

        $this->assertEquals(201, $pushTarget['headers']['status-code']);

        $futureTime4 = new \DateTime();
        $futureTime4->add(new \DateInterval('PT6M')); // 6 minutes from now
        $futureTime4->setTime(
            (int) $futureTime4->format('H'),
            (int) $futureTime4->format('i'),
            23 // Arbitrary seconds for push
        );

        $push = $this->client->call(Client::METHOD_POST, '/messaging/messages/push', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'title' => 'Test Push',
            'body' => 'Test push with arbitrary seconds',
            'targets' => [$pushTarget['body']['$id']],
            'scheduledAt' => $futureTime4->format('Y-m-d\TH:i:s.000\Z'),
        ]);

        $this->assertEquals(201, $push['headers']['status-code'], 'Failed to create push with arbitrary seconds');
        $this->assertEquals(MessageStatus::SCHEDULED, $push['body']['status']);
    }
}
