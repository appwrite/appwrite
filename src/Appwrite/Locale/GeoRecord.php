<?php

namespace Appwrite\Locale;

use Utopia\Database\Document;

class GeoRecord extends Document
{
    public function getIp(): string
    {
        return $this->getAttribute('ip', '');
    }

    public function getCountryCode(): string
    {
        return $this->getAttribute('countryCode', '');
    }

    public function getCountryName(): string
    {
        return $this->getAttribute('countryName', '');
    }

    public function getContinent(): string
    {
        return $this->getAttribute('continent', '');
    }

    public function getContinentCode(): string
    {
        return $this->getAttribute('continentCode', '');
    }

    public function isEu(): bool
    {
        return $this->getAttribute('eu', false);
    }

    public function getCurrency(): ?string
    {
        return $this->getAttribute('currency');
    }
}
