<?php

namespace Appwrite\Config\Adapter;

use Appwrite\Config\Adapter;
use Appwrite\Config\Exception\Parse;

class Dotenv extends Adapter
{
    public function parse(string $contents): array
    {
        $config = [];

        foreach (\explode("\n", $contents) as $line) {
            $pair = \strstr($line, '#', true);
            if ($pair === false) {
                $pair = $line;
            }
            $pair = \trim($pair);

            if (empty($pair)) {
                continue;
            }

            $parts = \explode('=', $pair, 2);
            $name = \trim($parts[0]);
            $value = \trim($parts[1] ?? '');

            if (empty($name)) {
                throw new Parse('Config file is not a valid dotenv file.');
            }

            $config[$name] = $value;
        }

        return $config;
    }
}
