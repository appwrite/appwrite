<?php

namespace Utopia\Auth;

class Store
{
    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    protected ?string $key = null;

    /**
     * Get a property from the store
     */
    public function getProperty(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Set a property in the store
     */
    public function setProperty(string $key, mixed $value): static
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Get the store key
     */
    public function getKey(): ?string
    {
        return $this->key;
    }

    /**
     * Set the store key
     */
    public function setKey(?string $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Encode store data to base64 string
     *
     *
     * @throws \JsonException
     */
    public function encode(): string
    {
        $json = json_encode($this->data, JSON_THROW_ON_ERROR);

        return base64_encode($json);
    }

    /**
     * Decode base64 string and populate current store instance
     */
    public function decode(string $data): static
    {
        try {
            $decoded = base64_decode($data, true);
            if ($decoded === false) {
                return $this;
            }

            $json = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
            if (\is_array($json)) {
                foreach ($json as $key => $value) {
                    $this->setProperty($key, $value);
                }
            }
        } catch (\JsonException) {
            // Invalid JSON, return empty store
        }

        return $this;
    }
}
