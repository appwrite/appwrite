<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class JWT extends Model
{
    private ?string $token = null;
    private ?array $payload = null;
    private ?string $algorithm = null;
    private ?string $type = null;
    private ?int $expiresIn = null;
    private ?string $issuer = null;
    private ?string $audience = null;
    private ?string $subject = null;
    private ?array $headers = null;

    public function __construct(?string $token = null)
    {
        $this->token = $token;
        
        if ($token !== null) {
            $this->parseToken();
        }
        
        $this
            ->addRule('jwt', [
                'type' => self::TYPE_STRING,
                'description' => 'JWT encoded string.',
                'example' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c',
            ])
        ;
    }

    /**
     * Parse JWT token into components
     *
     * @return void
     */
    private function parseToken(): void
    {
        if (empty($this->token)) {
            return;
        }

        $parts = explode('.', $this->token);
        if (count($parts) !== 3) {
            return;
        }

        // Parse header
        $headerDecoded = base64_decode($parts[0]);
        if ($headerDecoded !== false) {
            $this->headers = json_decode($headerDecoded, true);
        }

        // Parse payload
        $payloadDecoded = base64_decode($parts[1]);
        if ($payloadDecoded !== false) {
            $this->payload = json_decode($payloadDecoded, true);
        }
    }

    /**
     * Set JWT token
     *
     * @param string $token
     * @return self
     */
    public function setToken(string $token): self
    {
        $this->token = $token;
        return $this;
    }

    /**
     * Get JWT token
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * Set JWT payload
     *
     * @param array $payload
     * @return self
     */
    public function setPayload(array $payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    /**
     * Get JWT payload
     *
     * @return array|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /**
     * Set JWT algorithm
     *
     * @param string $algorithm
     * @return self
     */
    public function setAlgorithm(string $algorithm): self
    {
        $this->algorithm = $algorithm;
        return $this;
    }

    /**
     * Get JWT algorithm
     *
     * @return string|null
     */
    public function getAlgorithm(): ?string
    {
        return $this->algorithm;
    }

    /**
     * Set JWT type
     *
     * @param string $type
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Get JWT type
     *
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Set expiration time in seconds
     *
     * @param int $expiresIn
     * @return self
     */
    public function setExpiresIn(int $expiresIn): self
    {
        $this->expiresIn = $expiresIn;
        return $this;
    }

    /**
     * Get expiration time in seconds
     *
     * @return int|null
     */
    public function getExpiresIn(): ?int
    {
        return $this->expiresIn;
    }

    /**
     * Set JWT issuer
     *
     * @param string $issuer
     * @return self
     */
    public function setIssuer(string $issuer): self
    {
        $this->issuer = $issuer;
        return $this;
    }

    /**
     * Get JWT issuer
     *
     * @return string|null
     */
    public function getIssuer(): ?string
    {
        return $this->issuer;
    }

    /**
     * Set JWT audience
     *
     * @param string $audience
     * @return self
     */
    public function setAudience(string $audience): self
    {
        $this->audience = $audience;
        return $this;
    }

    /**
     * Get JWT audience
     *
     * @return string|null
     */
    public function getAudience(): ?string
    {
        return $this->audience;
    }

    /**
     * Set JWT subject
     *
     * @param string $subject
     * @return self
     */
    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Get JWT subject
     *
     * @return string|null
     */
    public function getSubject(): ?string
    {
        return $this->subject;
    }

    /**
     * Get JWT headers
     *
     * @return array|null
     */
    public function getHeaders(): ?array
    {
        return $this->headers;
    }

    /**
     * Validate JWT format
     *
     * @param string $token
     * @return bool
     */
    public function isValidFormat(string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        // JWT should have 3 parts separated by dots
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        // Basic format validation
        $header = base64_decode($parts[0]);
        $payload = base64_decode($parts[1]);
        $signature = $parts[2];

        return $header !== false && $payload !== false && !empty($signature);
    }

    /**
     * Decode JWT payload
     *
     * @param string $token
     * @return array|null
     */
    public function decodePayload(string $token): ?array
    {
        if (!$this->isValidFormat($token)) {
            return null;
        }

        $parts = explode('.', $token);
        $payload = base64_decode($parts[1]);
        
        if ($payload === false) {
            return null;
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Check if JWT is expired
     *
     * @param string $token
     * @return bool
     */
    public function isExpired(string $token): bool
    {
        $payload = $this->decodePayload($token);
        
        if ($payload === null || !isset($payload['exp'])) {
            return false;
        }

        return $payload['exp'] < time();
    }

    /**
     * Get issued at timestamp
     *
     * @param string $token
     * @return int|null
     */
    public function getIssuedAt(string $token): ?int
    {
        $payload = $this->decodePayload($token);
        
        if ($payload === null || !isset($payload['iat'])) {
            return null;
        }

        return $payload['iat'];
    }

    /**
     * Get not before timestamp
     *
     * @param string $token
     * @return int|null
     */
    public function getNotBefore(string $token): ?int
    {
        $payload = $this->decodePayload($token);
        
        if ($payload === null || !isset($payload['nbf'])) {
            return null;
        }

        return $payload['nbf'];
    }

    /**
     * Validate JWT against current model data
     *
     * @param string $token
     * @return bool
     */
    public function isValid(string $token): bool
    {
        if (!$this->isValidFormat($token)) {
            return false;
        }

        if ($this->isExpired($token)) {
            return false;
        }

        $payload = $this->decodePayload($token);
        if ($payload === null) {
            return false;
        }

        // Validate against model constraints
        if ($this->algorithm !== null && isset($payload['alg']) && $payload['alg'] !== $this->algorithm) {
            return false;
        }

        if ($this->type !== null && isset($payload['typ']) && $payload['typ'] !== $this->type) {
            return false;
        }

        if ($this->issuer !== null && isset($payload['iss']) && $payload['iss'] !== $this->issuer) {
            return false;
        }

        if ($this->audience !== null && isset($payload['aud']) && $payload['aud'] !== $this->audience) {
            return false;
        }

        if ($this->subject !== null && isset($payload['sub']) && $payload['sub'] !== $this->subject) {
            return false;
        }

        return true;
    }

    /**
     * Get JWT as array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'payload' => $this->payload,
            'algorithm' => $this->algorithm,
            'type' => $this->type,
            'issuer' => $this->issuer,
            'audience' => $this->audience,
            'subject' => $this->subject,
            'expiresIn' => $this->expiresIn,
            'headers' => $this->headers,
            'issuedAt' => $this->token ? $this->getIssuedAt($this->token) : null,
            'notBefore' => $this->token ? $this->getNotBefore($this->token) : null,
            'isExpired' => $this->token ? $this->isExpired($this->token) : false,
        ];
    }

    /**
     * Generate test JWT
     *
     * @param array $payload
     * @param string $algorithm
     * @param int $expiresIn
     * @return string
     */
    public static function generateTestJWT(array $payload, string $algorithm = 'HS256', int $expiresIn = 3600): string
    {
        $header = base64_encode(json_encode(['alg' => $algorithm, 'typ' => 'JWT']));
        
        $payload['exp'] = time() + $expiresIn;
        $payloadEncoded = base64_encode(json_encode($payload));
        
        // Simple HMAC signature for testing (not production ready)
        $signature = hash_hmac('sha256', $header . '.' . $payloadEncoded, 'test-secret');
        
        return $header . '.' . $payloadEncoded . '.' . $signature;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'JWT';
    }

    /**
     * Get Model Type
     *
     * @return string
     */
    public function getModelType(): string
    {
        return Response::MODEL_JWT;
    }
}
