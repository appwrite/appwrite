<?php

// yaml.*: yaml_parse (ext-yaml over libyaml, with the default ini). Values
// cross as Typed.

use Tests\Compat\PhpStd\Typed;

require_once __DIR__ . '/../Typed.php';

return [
    // yaml_parse($input): the first document, or false (its warning silenced).
    'yaml.parse' => function (array $a) {
        $value = @yaml_parse($a['input']);

        return $value === false ? false : Typed::dump($value);
    },
];
