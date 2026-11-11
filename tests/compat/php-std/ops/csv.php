<?php

// csv.*: str_getcsv() and fgetcsv() (every record of a file, until false).

use Tests\Compat\Session;

return [
    'csv.str_getcsv' => fn (array $a) => str_getcsv($a['input'], $a['separator'], $a['enclosure'], $a['escape']),
    'csv.fgetcsv' => function (array $a, Session $s) {
        $path = '/tmp/compat-fs/' . $s->ns . '/fgetcsv.csv';
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $a['input']);
        $handle = fopen($path, 'r');
        $rows = [];
        while (($row = fgetcsv($handle, 0, $a['separator'], $a['enclosure'], $a['escape'])) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        unlink($path);

        return $rows;
    },
];
