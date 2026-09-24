<?php

declare(strict_types=1);

namespace Utopia\Validator\JSON;

use PHPUnit\Framework\TestCase;
use Utopia\Validator;

final class FCMTest extends TestCase
{
    private const array CREDENTIALS = [
        'type' => 'service_account',
        'project_id' => 'test-project',
        'private_key_id' => 'test-private-key-id',
        'private_key' => "-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----\n",
        'client_email' => 'test@appwrite.iam.gserviceaccount.com',
        'client_id' => '1234567890',
        'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
        'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/test%40appwrite.iam.gserviceaccount.com',
        'universe_domain' => 'googleapis.com',
    ];

    public function testAcceptsEncodedServiceAccount(): void
    {
        $this->assertTrue(new FCM()->isValid(json_encode(self::CREDENTIALS)));
    }

    public function testAcceptsDecodedServiceAccounts(): void
    {
        $validator = new FCM();

        $this->assertTrue($validator->isValid(self::CREDENTIALS));
        $this->assertTrue($validator->isValid((object) self::CREDENTIALS));
    }

    public function testAcceptsOnlyOperationalFieldsAndUnknownFields(): void
    {
        $credentials = array_intersect_key(self::CREDENTIALS, array_flip([
            'type',
            'project_id',
            'private_key',
            'client_email',
            'token_uri',
        ]));
        $credentials['custom_field'] = 'allowed';

        $this->assertTrue(new FCM()->isValid($credentials));
    }

    /**
     * @param array<string, mixed> $credentials
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missingRequiredFieldProvider')]
    public function testRejectsMissingOrEmptyRequiredFields(array $credentials, string $field, string $purpose): void
    {
        $validator = new FCM();

        $this->assertFalse($validator->isValid($credentials));
        $this->assertSame(
            "FCM service account JSON must include a non-empty '{$field}' field, which {$purpose}.",
            $validator->getDescription(),
        );
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, string, string}>
     */
    public static function missingRequiredFieldProvider(): \Iterator
    {
        yield 'type' => [self::without('type'), 'type', 'identifies the credentials as a Google service account'];
        yield 'project ID' => [self::without('project_id'), 'project_id', 'identifies the Firebase project receiving messages'];
        yield 'private key' => [self::without('private_key'), 'private_key', 'signs the OAuth access-token request'];
        yield 'client email' => [self::without('client_email'), 'client_email', 'identifies the service account used for authentication'];
        yield 'token URI' => [self::without('token_uri'), 'token_uri', 'identifies the OAuth token endpoint'];
        yield 'blank project ID' => [array_replace(self::CREDENTIALS, ['project_id' => " \t\n"]), 'project_id', 'identifies the Firebase project receiving messages'];
        yield 'non-string client email' => [array_replace(self::CREDENTIALS, ['client_email' => 123]), 'client_email', 'identifies the service account used for authentication'];
    }

    /**
     * @param array<string, mixed> $credentials
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFieldProvider')]
    public function testRejectsInvalidFieldValues(array $credentials, string $description): void
    {
        $validator = new FCM();

        $this->assertFalse($validator->isValid($credentials));
        $this->assertSame($description, $validator->getDescription());
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, string}>
     */
    public static function invalidFieldProvider(): \Iterator
    {
        yield 'credential type' => [
            array_replace(self::CREDENTIALS, ['type' => 'authorized_user']),
            "FCM service account JSON field 'type' must be 'service_account'.",
        ];
        yield 'client email' => [
            array_replace(self::CREDENTIALS, ['client_email' => 'not-an-email']),
            "FCM service account JSON field 'client_email' must contain a valid email address.",
        ];
        yield 'private key' => [
            array_replace(self::CREDENTIALS, ['private_key' => 'not-a-private-key']),
            "FCM service account JSON field 'private_key' must contain a PEM-encoded private key.",
        ];
        yield 'HTTP token URI' => [
            array_replace(self::CREDENTIALS, ['token_uri' => 'http://oauth2.googleapis.com/token']),
            "FCM service account JSON field 'token_uri' must contain a valid HTTPS URL.",
        ];
        yield 'invalid auth URI' => [
            array_replace(self::CREDENTIALS, ['auth_uri' => 'not-a-url']),
            "FCM service account JSON field 'auth_uri' must contain a valid HTTPS URL.",
        ];
        yield 'empty optional field' => [
            array_replace(self::CREDENTIALS, ['private_key_id' => '']),
            "FCM service account JSON field 'private_key_id' must be a non-empty string when provided.",
        ];
        yield 'non-string optional field' => [
            array_replace(self::CREDENTIALS, ['client_id' => 123]),
            "FCM service account JSON field 'client_id' must be a non-empty string when provided.",
        ];
    }

    public function testRejectsInvalidJSONShapes(): void
    {
        $validator = new FCM();

        $this->assertFalse($validator->isValid('not-json'));
        $this->assertFalse($validator->isValid('[]'));
        $this->assertFalse($validator->isValid('null'));
        $this->assertFalse($validator->isValid('["credentials"]'));
        $this->assertFalse($validator->isValid(['credentials']));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(true));
    }

    public function testDescriptionResetsBetweenValidations(): void
    {
        $validator = new FCM();

        $this->assertFalse($validator->isValid([]));
        $this->assertNotSame('Value must be valid Google service account JSON for FCM', $validator->getDescription());

        $this->assertTrue($validator->isValid(self::CREDENTIALS));
        $this->assertSame('Value must be valid Google service account JSON for FCM', $validator->getDescription());
    }

    public function testContract(): void
    {
        $validator = new FCM();

        $this->assertFalse($validator->isArray());
        $this->assertSame(Validator::TYPE_OBJECT, $validator->getType());
        $this->assertSame('Value must be valid Google service account JSON for FCM', $validator->getDescription());
    }

    /**
     * @return array<string, string>
     */
    private static function without(string $field): array
    {
        $credentials = self::CREDENTIALS;
        unset($credentials[$field]);

        return $credentials;
    }
}
