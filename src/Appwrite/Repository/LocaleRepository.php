<?php

namespace Appwrite\Repository;

use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Locale\Locale;
use MaxMind\Db\Reader;

class LocaleRepository
{
    /**
     * @throws \Exception
     */
    public function get(Locale $locale, Reader $geodb, string $ip): array
    {
        $eu = Config::getParam('locale-eu');
        $currencies = Config::getParam('locale-currencies');
        $output = [];

        $output['ip'] = $ip;

        $currency = null;

        $record = $geodb->get($ip);

        if ($record) {
            $output['countryCode'] = $record['country']['iso_code'];
            $output['country'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            $output['continent'] = $locale->getText('continents.' . strtolower($record['continent']['code']), $locale->getText('locale.country.unknown'));
            $output['continent'] = (isset($continents[$record['continent']['code']])) ? $continents[$record['continent']['code']] : $locale->getText('locale.country.unknown');
            $output['continentCode'] = $record['continent']['code'];
            $output['eu'] = (\in_array($record['country']['iso_code'], $eu)) ? true : false;

            foreach ($currencies as $code => $element) {
                if (isset($element['locations']) && isset($element['code']) && \in_array($record['country']['iso_code'], $element['locations'])) {
                    $currency = $element['code'];
                }
            }

            $output['currency'] = $currency;
        } else {
            $output['countryCode'] = '--';
            $output['country'] = $locale->getText('locale.country.unknown');
            $output['continent'] = $locale->getText('locale.country.unknown');
            $output['continentCode'] = '--';
            $output['eu'] = false;
            $output['currency'] = $currency;
        }

        return $output;
    }

    /**
     * @throws \Exception
     */
    public function getCountries(Locale $locale): array
    {
        $list = Config::getParam('locale-countries');

        /* @var $list array */
        $output = [];

        foreach ($list as $value) {
            $output[] = new Document([
                'name' => $locale->getText('countries.' . strtolower($value)),
                'code' => $value,
            ]);
        }

        usort($output, function ($a, $b) {
            return strcmp($a->getAttribute('name'), $b->getAttribute('name'));
        });

        return $output;
    }

    /**
     * @throws \Exception
     */
    public function getCountriesEU(Locale $locale): array
    {
        $eu = Config::getParam('locale-eu');
        $output = [];

        foreach ($eu as $code) {
            if ($locale->getText('countries.' . strtolower($code), false) !== false) {
                $output[] = new Document([
                    'name' => $locale->getText('countries.' . strtolower($code)),
                    'code' => $code,
                ]);
            }
        }

        usort($output, function ($a, $b) {
            return strcmp($a->getAttribute('name'), $b->getAttribute('name'));
        });

        return $output;
    }

    /**
     * @throws \Exception
     */
    public function getCountriesPhones(Locale $locale): array
    {
        $list = Config::getParam('locale-phones');
        /* @var $list array */
        $output = [];

        \asort($list);

        foreach ($list as $code => $name) {
            if ($locale->getText('countries.' . strtolower($code), false) !== false) {
                $output[] = new Document([
                    'code' => '+' . $name,
                    'countryCode' => $code,
                    'countryName' => $locale->getText('countries.' . strtolower($code)),
                ]);
            }
        }

        return $output;
    }

    /**
     * @throws \Exception
     */
    public function getContinents(Locale $locale): array
    {
        $list = Config::getParam('locale-continents');
        /* @var $list array */

        foreach ($list as $key => $value) {
            $output[] = new Document([
                'name' => $locale->getText('continents.' . strtolower($value)),
                'code' => $value,
            ]);
        }

        usort($output, function ($a, $b) {
            return strcmp($a->getAttribute('name'), $b->getAttribute('name'));
        });

        return $output;
    }

    /**
     * @throws \Exception
     */
    public function getCurrencies(): array
    {
        $list = Config::getParam('locale-currencies');

        return array_map(fn($node) => new Document($node), $list);
    }

    /**
     * @throws \Exception
     */
    public function getLanguages(): array
    {
        $list = Config::getParam('locale-languages');

        return array_map(fn($node) => new Document($node), $list);

    }
}