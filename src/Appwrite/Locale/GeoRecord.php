<?php

namespace Appwrite\Locale;

use Utopia\Database\Document;
use Utopia\Locale\Locale;

class GeoRecord extends Document
{
    private ?Locale $locale = null;

    private bool $lookupSucceeded = false;

    public function setLocale(Locale $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function setLookupSucceeded(bool $succeeded): self
    {
        $this->lookupSucceeded = $succeeded;

        return $this;
    }

    /**
     * True when the geo service returned a well-formed response for this IP,
     * even if the response reported an unknown country (e.g. IP not in the DB).
     * False when the service was unreachable, misconfigured, or errored.
     */
    public function isLookupSucceeded(): bool
    {
        return $this->lookupSucceeded;
    }

    public function isEmpty(): bool
    {
        return $this->getAttribute('countryCode', '--') === '--';
    }

    public function getIp(): string
    {
        return $this->getAttribute('ip', '');
    }

    public function getCountryCode(): string
    {
        $code = $this->getAttribute('countryCode', '--');

        if ($code === '--') {
            return '--';
        }

        $upper = \strtoupper($code);

        if ($this->locale === null) {
            return $upper;
        }

        return $this->locale->getText('countries.' . \strtolower($upper), false) ? $upper : '--';
    }

    public function getCountryName(): string
    {
        if ($this->locale === null) {
            return '';
        }

        $unknown = $this->locale->getText('locale.country.unknown');
        $code = $this->getAttribute('countryCode', '--');

        if ($code === '--') {
            return $unknown;
        }

        return $this->locale->getText('countries.' . \strtolower($code), $unknown);
    }

    public function getContinent(): string
    {
        if ($this->locale === null) {
            return '';
        }

        $unknown = $this->locale->getText('locale.country.unknown');
        $code = $this->getAttribute('continentCode', '--');

        if ($code === '--') {
            return $unknown;
        }

        return $this->locale->getText('continents.' . \strtolower($code), $unknown);
    }

    public function getContinentCode(): string
    {
        return $this->getAttribute('continentCode', '--');
    }

    public function isEu(): bool
    {
        return $this->getAttribute('eu', false);
    }

    public function getCurrency(): ?string
    {
        return $this->getAttribute('currency');
    }

    public function getTimeZone(): ?string
    {
        return $this->getAttribute('timeZone');
    }

    public function getWeatherCode(): ?string
    {
        return $this->getAttribute('weatherCode');
    }

    public function getPostalCode(): ?string
    {
        return $this->getAttribute('postalCode');
    }

    public function getLatitude(): ?float
    {
        $value = $this->getAttribute('latitude');

        return $value === null ? null : (float) $value;
    }

    public function getLongitude(): ?float
    {
        $value = $this->getAttribute('longitude');

        return $value === null ? null : (float) $value;
    }

    public function getIsp(): ?string
    {
        return $this->getAttribute('isp');
    }

    public function getAutonomousSystemNumber(): ?string
    {
        return $this->getAttribute('autonomousSystemNumber');
    }

    public function getAutonomousSystemOrganization(): ?string
    {
        return $this->getAttribute('autonomousSystemOrganization');
    }

    public function getConnectionType(): ?string
    {
        return $this->getAttribute('connectionType');
    }

    public function getConnectionUsageType(): ?string
    {
        return $this->getAttribute('connectionUsageType');
    }

    public function getConnectionOrganization(): ?string
    {
        return $this->getAttribute('connectionOrganization');
    }
}
