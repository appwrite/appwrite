<?php

namespace Appwrite\Network\Geo;

/**
 * GeoRecord provides typed accessors for geolocation metadata
 * Abstracts geolocation data from various sources (Docker geo service, MaxMind, etc.)
 */
class GeoRecord
{
    private ?array $data;

    public function __construct(?array $data = null)
    {
        $this->data = $data;
    }

    /**
     * Check if record has valid data
     */
    public function isValid(): bool
    {
        return $this->data !== null && !empty($this->data);
    }

    /**
     * Get country ISO code
     */
    public function getCountryCode(): ?string
    {
        return $this->data['country']['iso_code'] ?? null;
    }

    /**
     * Get country name
     */
    public function getCountryName(): ?string
    {
        return $this->data['country']['name'] ?? null;
    }

    /**
     * Get continent code
     */
    public function getContinentCode(): ?string
    {
        return $this->data['continent']['code'] ?? null;
    }

    /**
     * Get continent name
     */
    public function getContinentName(): ?string
    {
        return $this->data['continent']['name'] ?? null;
    }

    /**
     * Get city name
     */
    public function getCity(): ?string
    {
        return $this->data['city']['name'] ?? null;
    }

    /**
     * Get postal code
     */
    public function getPostalCode(): ?string
    {
        return $this->data['postal']['code'] ?? null;
    }

    /**
     * Get latitude
     */
    public function getLatitude(): ?float
    {
        return $this->data['location']['latitude'] ?? null;
    }

    /**
     * Get longitude
     */
    public function getLongitude(): ?float
    {
        return $this->data['location']['longitude'] ?? null;
    }

    /**
     * Get timezone
     */
    public function getTimezone(): ?string
    {
        return $this->data['location']['time_zone'] ?? null;
    }

    /**
     * Get raw data array
     */
    public function toArray(): ?array
    {
        return $this->data;
    }

    /**
     * Create from MaxMind Reader result
     */
    public static function fromMaxMind(?array $data): self
    {
        return new self($data);
    }
}
