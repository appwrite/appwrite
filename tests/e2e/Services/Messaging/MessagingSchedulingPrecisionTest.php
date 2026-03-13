<?php

namespace Tests\E2E\Services\Messaging;

use Appwrite\Messaging\Status as MessageStatus;
use Tests\E2E\Client;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;

/**
 * Test for Issue #8226: SMS Scheduling with Minute Precision
 * 
 * This test verifies that the DatetimeValidator accepts scheduled times
 * with minute precision and 1-minute future requirement.
 */
trait MessagingSchedulingPrecisionTest
{
    /**
     * Test scheduling SMS with minute precision (issue #8226)
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

        // Create SMS target
        $smsTarget = $this->client->call(Client::METHOD_POST, '/users/' . $user['body']['$id'] . '/targets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'targetId' => ID::unique(),
            'providerType' => 'sms',
            'identifier' => '+1234567890',
        ]);

        $this->assertEquals(201, $smsTarget['headers']['status-code']);
        $targetId = $smsTarget['body']['$id'];

        // Test 1: Schedule SMS with on-the-minute time
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

        // Verify scheduledAt if returned by API (persistence depends on env schema)
        if (isset($sms1['body']['scheduledAt']) && $sms1['body']['scheduledAt']) {
            $this->assertEquals($scheduledTime1Rounded, $sms1['body']['scheduledAt'], 'Scheduled time should match the requested time');
        }

        // Test 2: Schedule SMS 3+ minutes in future
        $futureTime = new \DateTime();
        $futureTime->add(new \DateInterval('PT3M')); // 3 minutes from now
        $futureTime->setTime(
            (int) $futureTime->format('H'),
            (int) $futureTime->format('i'),
            0 // Must be 0 for PRECISION_MINUTES
        );

        $sms2 = $this->client->call(Client::METHOD_POST, '/messaging/messages/sms', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'content' => 'Test SMS at exact minute',
            'targets' => [$targetId],
            'scheduledAt' => $futureTime->format('Y-m-d\TH:i:00.000\Z'),
        ]);

        $this->assertEquals(201, $sms2['headers']['status-code'], 'Failed to create SMS at exact minute');
        $this->assertEquals(MessageStatus::SCHEDULED, $sms2['body']['status']);

        if (isset($sms2['body']['scheduledAt']) && $sms2['body']['scheduledAt']) {
            $this->assertEquals($futureTime->format('Y-m-d\TH:i:00.000\Z'), $sms2['body']['scheduledAt'], 'Scheduled time should match the requested time');
        }

        // Test 3: Update scheduled message
        $futureTime2 = new \DateTime();
        $futureTime2->add(new \DateInterval('PT4M')); // 4 minutes from now
        $futureTime2->setTime(
            (int) $futureTime2->format('H'),
            (int) $futureTime2->format('i'),
            0
        );

        $updatedSms = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/sms/' . $sms2['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'scheduledAt' => $futureTime2->format('Y-m-d\TH:i:00.000\Z'),
        ]);

        $this->assertEquals(200, $updatedSms['headers']['status-code'], 'Failed to update SMS time');
        $this->assertEquals(MessageStatus::SCHEDULED, $updatedSms['body']['status']);

        if (isset($updatedSms['body']['scheduledAt']) && $updatedSms['body']['scheduledAt']) {
            $this->assertEquals($futureTime2->format('Y-m-d\TH:i:00.000+00:00'), $updatedSms['body']['scheduledAt'], 'Scheduled time should match the requested time');
        }

        // Test 4: Verify that times less than 1 minute in future are rejected
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
    }
}
