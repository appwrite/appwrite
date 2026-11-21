<?php

// path.*: basename(), pathinfo($path, PATHINFO_EXTENSION) and fnmatch().

return [
    'path.basename' => fn (array $a) => basename($a['path']),
    'path.pathinfo_extension' => fn (array $a) => pathinfo($a['path'], PATHINFO_EXTENSION),
    'path.fnmatch' => fn (array $a) => fnmatch($a['pattern'], $a['filename']),
];
