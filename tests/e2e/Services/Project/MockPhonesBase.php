<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

trait MockPhonesBase
{
    // Create mock phone tests

    public function testCreateMockPhone(): void
    {
        $number = $this->uniquePhoneNumber();

        $response = $this->createMockPhone($number, '123456');

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($number, $response['body']['number']);
        $this->assertSame('123456', $response['body']['otp']);

        $dateValidator = new DatetimeValidator();
        $this->assertTrue($dateValidator->isValid($response['body']['$createdAt']));
        $this->assertTrue($dateValidator->isValid($response['body']['$updatedAt']));

        // Verify via GET
        $get = $this->getMockPhone($number);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($number, $get['body']['number']);
        $this->assertSame('123456', $get['body']['otp']);

        // Verify via LIST
        $list = $this->listMockPhones();
        $this->assertSame(200, $list['headers']['status-code']);
        $numbers = \array_column($list['body']['mockNumbers'], 'number');
        $this->assertContains($number, $numbers);

        // Cleanup
        $this->deleteMockPhone($number);
    }

    public function testCreateMockPhoneAlreadyExists(): void
    {
        $number = $this->uniquePhoneNumber();

        $first = $this->createMockPhone($number, '123456');
        $this->assertSame(201, $first['headers']['status-code']);

        $duplicate = $this->createMockPhone($number, '654321');
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('mock_number_already_exists', $duplicate['body']['type']);

        // Original OTP must remain unchanged
        $get = $this->getMockPhone($number);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('123456', $get['body']['otp']);

        // Cleanup
        $this->deleteMockPhone($number);
    }

    public function testCreateMockPhoneInvalidNumber(): void
    {
        // Missing `+` prefix — Phone validator rejects.
        $response = $this->createMockPhone('16555551234', '123456');
        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateMockPhoneNumberTooLong(): void
    {
        // 16 digits exceeds the E.164 15-digit maximum.
        $response = $this->createMockPhone('+1234567890987654', '123456');
        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateMockPhoneInvalidOtpTooShort(): void
    {
        $response = $this->createMockPhone($this->uniquePhoneNumber(), '123');
        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateMockPhoneInvalidOtpTooLong(): void
    {
        $response = $this->createMockPhone($this->uniquePhoneNumber(), '1234567');
        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateMockPhoneInvalidOtpNonNumeric(): void
    {
        $response = $this->createMockPhone($this->uniquePhoneNumber(), 'abc123');
        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateMockPhoneMissingNumber(): void
    {
        $response = $this->createMockPhone(null, '123456');
        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateMockPhoneMissingOtp(): void
    {
        $response = $this->createMockPhone($this->uniquePhoneNumber(), null);
        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateMockPhoneWithoutAuthentication(): void
    {
        $response = $this->createMockPhone($this->uniquePhoneNumber(), '123456', authenticated: false);
        $this->assertSame(401, $response['headers']['status-code']);
    }

    // Get mock phone tests

    public function testGetMockPhone(): void
    {
        $number = $this->uniquePhoneNumber();
        $create = $this->createMockPhone($number, '987654');
        $this->assertSame(201, $create['headers']['status-code']);

        $response = $this->getMockPhone($number);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($number, $response['body']['number']);
        $this->assertSame('987654', $response['body']['otp']);

        $dateValidator = new DatetimeValidator();
        $this->assertTrue($dateValidator->isValid($response['body']['$createdAt']));
        $this->assertTrue($dateValidator->isValid($response['body']['$updatedAt']));

        // Cleanup
        $this->deleteMockPhone($number);
    }

    public function testGetMockPhoneNotFound(): void
    {
        $response = $this->getMockPhone($this->uniquePhoneNumber());

        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame('mock_number_not_found', $response['body']['type']);
    }

    public function testGetMockPhoneInvalidNumber(): void
    {
        // Path param is still validated with the Phone validator.
        $response = $this->getMockPhone('not-a-phone');
        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testGetMockPhoneWithoutAuthentication(): void
    {
        $number = $this->uniquePhoneNumber();
        $create = $this->createMockPhone($number, '123456');
        $this->assertSame(201, $create['headers']['status-code']);

        $response = $this->getMockPhone($number, authenticated: false);
        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deleteMockPhone($number);
    }

    // Update mock phone tests

    public function testUpdateMockPhone(): void
    {
        $number = $this->uniquePhoneNumber();
        $create = $this->createMockPhone($number, '111111');
        $this->assertSame(201, $create['headers']['status-code']);

        $createdAt = $create['body']['$createdAt'];

        // Sleep a bit so $updatedAt shifts noticeably — makes the assertion below meaningful.
        \sleep(1);

        $update = $this->updateMockPhone($number, '222222');

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame($number, $update['body']['number']);
        $this->assertSame('222222', $update['body']['otp']);
        $this->assertSame($createdAt, $update['body']['$createdAt']);
        $this->assertNotSame($createdAt, $update['body']['$updatedAt']);

        // Verify persistence via GET
        $get = $this->getMockPhone($number);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('222222', $get['body']['otp']);

        // Cleanup
        $this->deleteMockPhone($number);
    }

    public function testUpdateMockPhoneNotFound(): void
    {
        $response = $this->updateMockPhone($this->uniquePhoneNumber(), '123456');

        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame('mock_number_not_found', $response['body']['type']);
    }

    public function testUpdateMockPhoneInvalidOtp(): void
    {
        $number = $this->uniquePhoneNumber();
        $create = $this->createMockPhone($number, '123456');
        $this->assertSame(201, $create['headers']['status-code']);

        $response = $this->updateMockPhone($number, 'abc123');
        $this->assertSame(400, $response['headers']['status-code']);

        // Original OTP must remain unchanged
        $get = $this->getMockPhone($number);
        $this->assertSame('123456', $get['body']['otp']);

        // Cleanup
        $this->deleteMockPhone($number);
    }

    public function testUpdateMockPhoneMissingOtp(): void
    {
        $number = $this->uniquePhoneNumber();
        $create = $this->createMockPhone($number, '123456');
        $this->assertSame(201, $create['headers']['status-code']);

        $response = $this->updateMockPhone($number, null);
        $this->assertSame(400, $response['headers']['status-code']);

        // Cleanup
        $this->deleteMockPhone($number);
    }

    public function testUpdateMockPhoneWithoutAuthentication(): void
    {
        $number = $this->uniquePhoneNumber();
        $create = $this->createMockPhone($number, '123456');
        $this->assertSame(201, $create['headers']['status-code']);

        $response = $this->updateMockPhone($number, '654321', authenticated: false);
        $this->assertSame(401, $response['headers']['status-code']);

        // Verify it's unchanged
        $get = $this->getMockPhone($number);
        $this->assertSame('123456', $get['body']['otp']);

        // Cleanup
        $this->deleteMockPhone($number);
    }

    // List mock phones tests

    public function testListMockPhones(): void
    {
        $number1 = $this->uniquePhoneNumber();
        $number2 = $this->uniquePhoneNumber();
        $number3 = $this->uniquePhoneNumber();

        $this->assertSame(201, $this->createMockPhone($number1, '111111')['headers']['status-code']);
        $this->assertSame(201, $this->createMockPhone($number2, '222222')['headers']['status-code']);
        $this->assertSame(201, $this->createMockPhone($number3, '333333')['headers']['status-code']);

        $response = $this->listMockPhones();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('mockNumbers', $response['body']);
        $this->assertArrayHasKey('total', $response['body']);
        $this->assertIsArray($response['body']['mockNumbers']);
        $this->assertIsInt($response['body']['total']);
        $this->assertGreaterThanOrEqual(3, $response['body']['total']);
        $this->assertGreaterThanOrEqual(3, \count($response['body']['mockNumbers']));

        // Verify shape of each entry
        foreach ($response['body']['mockNumbers'] as $entry) {
            $this->assertArrayHasKey('number', $entry);
            $this->assertArrayHasKey('otp', $entry);
            $this->assertArrayHasKey('$createdAt', $entry);
            $this->assertArrayHasKey('$updatedAt', $entry);
        }

        // All three seeded phones must be in the list
        $numbers = \array_column($response['body']['mockNumbers'], 'number');
        $this->assertContains($number1, $numbers);
        $this->assertContains($number2, $numbers);
        $this->assertContains($number3, $numbers);

        // Cleanup
        $this->deleteMockPhone($number1);
        $this->deleteMockPhone($number2);
        $this->deleteMockPhone($number3);
    }

    public function testListMockPhonesTotalFalse(): void
    {
        $number = $this->uniquePhoneNumber();
        $create = $this->createMockPhone($number, '123456');
        $this->assertSame(201, $create['headers']['status-code']);

        $response = $this->listMockPhones(total: false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(0, $response['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($response['body']['mockNumbers']));

        // Cleanup
        $this->deleteMockPhone($number);
    }

    public function testListMockPhonesTotalMatchesCount(): void
    {
        $number = $this->uniquePhoneNumber();
        $create = $this->createMockPhone($number, '123456');
        $this->assertSame(201, $create['headers']['status-code']);

        $response = $this->listMockPhones();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(\count($response['body']['mockNumbers']), $response['body']['total']);

        // Cleanup
        $this->deleteMockPhone($number);
    }

    public function testListMockPhonesWithLimit(): void
    {
        $number1 = $this->uniquePhoneNumber();
        $number2 = $this->uniquePhoneNumber();

        $this->assertSame(201, $this->createMockPhone($number1, '111111')['headers']['status-code']);
        $this->assertSame(201, $this->createMockPhone($number2, '222222')['headers']['status-code']);

        $response = $this->listMockPhones([
            Query::limit(1)->toString(),
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['mockNumbers']);
        $this->assertGreaterThanOrEqual(2, $response['body']['total']);

        // Cleanup
        $this->deleteMockPhone($number1);
        $this->deleteMockPhone($number2);
    }

    public function testListMockPhonesWithOffset(): void
    {
        $number1 = $this->uniquePhoneNumber();
        $number2 = $this->uniquePhoneNumber();

        $this->assertSame(201, $this->createMockPhone($number1, '111111')['headers']['status-code']);
        $this->assertSame(201, $this->createMockPhone($number2, '222222')['headers']['status-code']);

        $listAll = $this->listMockPhones();
        $this->assertSame(200, $listAll['headers']['status-code']);
        $totalAll = \count($listAll['body']['mockNumbers']);

        $listOffset = $this->listMockPhones([
            Query::offset(1)->toString(),
        ]);

        $this->assertSame(200, $listOffset['headers']['status-code']);
        $this->assertCount($totalAll - 1, $listOffset['body']['mockNumbers']);
        $this->assertSame($listAll['body']['total'], $listOffset['body']['total']);

        // Cleanup
        $this->deleteMockPhone($number1);
        $this->deleteMockPhone($number2);
    }

    public function testListMockPhonesWithoutAuthentication(): void
    {
        $response = $this->listMockPhones(authenticated: false);
        $this->assertSame(401, $response['headers']['status-code']);
    }

    // Delete mock phone tests

    public function testDeleteMockPhone(): void
    {
        $number = $this->uniquePhoneNumber();
        $create = $this->createMockPhone($number, '123456');
        $this->assertSame(201, $create['headers']['status-code']);

        // Confirm it exists
        $this->assertSame(200, $this->getMockPhone($number)['headers']['status-code']);

        $response = $this->deleteMockPhone($number);
        $this->assertSame(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        // Confirm it is gone
        $get = $this->getMockPhone($number);
        $this->assertSame(404, $get['headers']['status-code']);
        $this->assertSame('mock_number_not_found', $get['body']['type']);
    }

    public function testDeleteMockPhoneNotFound(): void
    {
        $response = $this->deleteMockPhone($this->uniquePhoneNumber());

        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame('mock_number_not_found', $response['body']['type']);
    }

    public function testDeleteMockPhoneDoubleDelete(): void
    {
        $number = $this->uniquePhoneNumber();
        $this->assertSame(201, $this->createMockPhone($number, '123456')['headers']['status-code']);

        $first = $this->deleteMockPhone($number);
        $this->assertSame(204, $first['headers']['status-code']);

        $second = $this->deleteMockPhone($number);
        $this->assertSame(404, $second['headers']['status-code']);
        $this->assertSame('mock_number_not_found', $second['body']['type']);
    }

    public function testDeleteMockPhoneRemovedFromList(): void
    {
        $number = $this->uniquePhoneNumber();
        $create = $this->createMockPhone($number, '123456');
        $this->assertSame(201, $create['headers']['status-code']);

        $before = $this->listMockPhones();
        $this->assertSame(200, $before['headers']['status-code']);
        $this->assertContains($number, \array_column($before['body']['mockNumbers'], 'number'));
        $countBefore = $before['body']['total'];

        $delete = $this->deleteMockPhone($number);
        $this->assertSame(204, $delete['headers']['status-code']);

        $after = $this->listMockPhones();
        $this->assertSame(200, $after['headers']['status-code']);
        $this->assertSame($countBefore - 1, $after['body']['total']);
        $this->assertNotContains($number, \array_column($after['body']['mockNumbers'], 'number'));
    }

    public function testDeleteMockPhoneWithoutAuthentication(): void
    {
        $number = $this->uniquePhoneNumber();
        $create = $this->createMockPhone($number, '123456');
        $this->assertSame(201, $create['headers']['status-code']);

        $response = $this->deleteMockPhone($number, authenticated: false);
        $this->assertSame(401, $response['headers']['status-code']);

        // Still present
        $this->assertSame(200, $this->getMockPhone($number)['headers']['status-code']);

        // Cleanup
        $this->deleteMockPhone($number);
    }

    // Helpers

    protected function createMockPhone(?string $number, ?string $otp, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        $params = [];
        if ($number !== null) {
            $params['number'] = $number;
        }
        if ($otp !== null) {
            $params['otp'] = $otp;
        }

        return $this->client->call(Client::METHOD_POST, '/project/mock-phones', $headers, $params);
    }

    protected function getMockPhone(string $number, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_GET, '/project/mock-phones/' . $number, $headers);
    }

    protected function updateMockPhone(string $number, ?string $otp, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        $params = [];
        if ($otp !== null) {
            $params['otp'] = $otp;
        }

        return $this->client->call(Client::METHOD_PUT, '/project/mock-phones/' . $number, $headers, $params);
    }

    protected function listMockPhones(?array $queries = null, ?bool $total = null, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        $params = [];
        if ($queries !== null) {
            $params['queries'] = $queries;
        }
        if ($total !== null) {
            $params['total'] = $total;
        }

        return $this->client->call(Client::METHOD_GET, '/project/mock-phones', $headers, $params);
    }

    protected function deleteMockPhone(string $number, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_DELETE, '/project/mock-phones/' . $number, $headers);
    }

    protected function uniquePhoneNumber(): string
    {
        // E.164: leading '+', first digit 1-9, 10 more digits. Randomised to avoid
        // collisions between interleaved tests that all live in the same project.
        return '+1' . \random_int(2000000000, 9999999999);
    }
}
