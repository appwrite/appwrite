<?php

declare(strict_types=1);

namespace Utopia\Validator\JSON;

use Utopia\Validator;

/**
 * Validate Google service account credentials used with the FCM HTTP v1 API.
 */
class FCM extends Validator
{
    /**
     * Fields required to authenticate and address an FCM HTTP v1 request.
     *
     * @var array<string, string>
     */
    private const array REQUIRED_FIELDS = [
        'type' => 'identifies the credentials as a Google service account',
        'project_id' => 'identifies the Firebase project receiving messages',
        'private_key' => 'signs the OAuth access-token request',
        'client_email' => 'identifies the service account used for authentication',
        'token_uri' => 'identifies the OAuth token endpoint',
    ];

    /**
     * Standard service account fields that are validated when present.
     *
     * @var string[]
     */
    private const array OPTIONAL_FIELDS = [
        'private_key_id',
        'client_id',
        'auth_uri',
        'auth_provider_x509_cert_url',
        'client_x509_cert_url',
        'universe_domain',
    ];

    /**
     * @var string[]
     */
    private const array URL_FIELDS = [
        'auth_uri',
        'token_uri',
        'auth_provider_x509_cert_url',
        'client_x509_cert_url',
    ];

    private ?string $error = null;

    public function getDescription(): string
    {
        return $this->error ?? 'Value must be valid Google service account JSON for FCM';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }

    public function isValid(mixed $value): bool
    {
        $this->error = null;

        if (\is_string($value)) {
            $value = json_decode($value);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }
        }

        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        } elseif (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            return false;
        }

        foreach (self::REQUIRED_FIELDS as $field => $purpose) {
            if (!$this->isNonEmptyString($value[$field] ?? null)) {
                $this->error = "FCM service account JSON must include a non-empty '{$field}' field, which {$purpose}.";

                return false;
            }
        }

        foreach (self::OPTIONAL_FIELDS as $field) {
            if (\array_key_exists($field, $value) && !$this->isNonEmptyString($value[$field])) {
                $this->error = "FCM service account JSON field '{$field}' must be a non-empty string when provided.";

                return false;
            }
        }

        if ($value['type'] !== 'service_account') {
            $this->error = "FCM service account JSON field 'type' must be 'service_account'.";

            return false;
        }

        if (filter_var($value['client_email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->error = "FCM service account JSON field 'client_email' must contain a valid email address.";

            return false;
        }

        if (!str_starts_with((string) $value['private_key'], '-----BEGIN PRIVATE KEY-----')
            || !str_ends_with(trim((string) $value['private_key']), '-----END PRIVATE KEY-----')) {
            $this->error = "FCM service account JSON field 'private_key' must contain a PEM-encoded private key.";

            return false;
        }

        foreach (self::URL_FIELDS as $field) {
            if (isset($value[$field]) && !$this->isHttpsUrl($value[$field])) {
                $this->error = "FCM service account JSON field '{$field}' must contain a valid HTTPS URL.";

                return false;
            }
        }

        return true;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return \is_string($value) && trim($value) !== '';
    }

    private function isHttpsUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https';
    }
}
