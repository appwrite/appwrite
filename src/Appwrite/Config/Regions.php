<?php

namespace Appwrite\Config;

final class Regions
{
    public const ID_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * Parse `_APP_REGIONS` JSON into the regions config shape.
     *
     * @param string $json
     * @return array
     * @throws \InvalidArgumentException
     */
    public static function parse(string $json): array
    {
        $decoded = \json_decode($json, true);

        if (!\is_array($decoded) || empty($decoded)) {
            throw new \InvalidArgumentException('Invalid _APP_REGIONS value. Must be a non-empty JSON object or array.');
        }

        $isList = \array_is_list($decoded);
        $catalog = [];

        foreach ($decoded as $key => $entry) {
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException('Invalid _APP_REGIONS value. Each entry must be an object.');
            }

            $id = $entry['$id'] ?? ($isList ? null : $key);

            if (!\is_string($id) || empty($id)) {
                throw new \InvalidArgumentException('Invalid _APP_REGIONS value. Each region must include a non-empty "$id".');
            }

            if (\preg_match(self::ID_PATTERN, $id) !== 1) {
                throw new \InvalidArgumentException('Invalid region id "' . $id . '". Use a lowercase DNS label (a-z, 0-9, hyphen).');
            }

            if (!$isList && $key !== $id) {
                throw new \InvalidArgumentException('Invalid _APP_REGIONS value. Object key must match "$id".');
            }

            if (isset($catalog[$id])) {
                throw new \InvalidArgumentException('Duplicate region id "' . $id . '" in _APP_REGIONS.');
            }

            $name = $entry['name'] ?? $id;

            if (!\is_string($name) || empty($name)) {
                throw new \InvalidArgumentException('Invalid _APP_REGIONS value. Region "' . $id . '" must include a non-empty "name".');
            }

            $catalog[$id] = [
                '$id' => $id,
                'name' => $name,
                'disabled' => (bool) ($entry['disabled'] ?? false),
                'default' => (bool) ($entry['default'] ?? false),
            ];
        }

        $defaults = \array_filter($catalog, fn (array $region) => $region['default'] === true);

        if (\count($defaults) !== 1) {
            throw new \InvalidArgumentException('Invalid _APP_REGIONS value. Exactly one region must have "default": true.');
        }

        return $catalog;
    }

    /**
     * Match pool/DSN keys to a region using `_db_<region>_` token boundaries.
     *
     * @param string $poolKey
     * @param string $region
     * @return bool
     */
    public static function poolKeyMatchesRegion(string $poolKey, string $region): bool
    {
        if (empty($poolKey) || empty($region)) {
            return false;
        }

        return \preg_match('/(^|_)db_' . \preg_quote($region, '/') . '(_|$)/', $poolKey) === 1;
    }

    /**
     * @param array $keys
     * @param string $region
     * @return array
     */
    public static function filterPoolKeysForRegion(array $keys, string $region): array
    {
        return \array_values(\array_filter($keys, function ($value) use ($region) {
            return \is_string($value) && self::poolKeyMatchesRegion($value, $region);
        }));
    }
}
