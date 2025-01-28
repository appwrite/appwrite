<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Extend\Exception as AppwriteException;

class Exception extends AppwriteException
{
    protected string $response = '';
    protected string $error = '';
    protected string $errorDescription = '';

    public function __construct(string $response = '', int $code = 0, \Throwable $previous = null)
    {
        $this->response = $response;
        $this->message = $response;
        $decoded = json_decode($response, true);
        if (\is_array($decoded)) {
            if (\is_array($decoded['error'] ?? '')) {
                $this->error = $decoded['error']['status'] ?? 'Unknown error';
                $this->errorDescription = $decoded['error']['message'] ?? 'No description';
            } elseif (\is_array($decoded['errors'] ?? '')) {
                $this->error = $decoded['error'] ?? $decoded['message'] ?? 'Unknown error';
                $this->errorDescription = $decoded['errors'][0]['message'] ?? 'No description';
            } else {
                $this->error = $decoded['error'] ?? $decoded['message'] ?? 'Unknown error';
                $this->errorDescription = $decoded['error_description'] ?? 'No description';
            }

            $this->message = $this->error . ': ' . $this->errorDescription;
        }
        $type = match ($code) {
            400 => AppwriteException::USER_OAUTH2_BAD_REQUEST,
            401 => AppwriteException::USER_OAUTH2_UNAUTHORIZED,
            default => AppwriteException::USER_OAUTH2_PROVIDER_ERROR
        };

        parent::__construct($type, $this->message, $code, $previous);
    }

    /**
     * Get the error parameter from the response.
     *
     * See https://datatracker.ietf.org/doc/html/rfc6749#section-5.2 for more information.
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * Get the error_description parameter from the response.
     *
     * See https://datatracker.ietf.org/doc/html/rfc6749#section-5.2 for more information.
     */
    public function getErrorDescription(): string
    {
        return $this->errorDescription;
    }
}
