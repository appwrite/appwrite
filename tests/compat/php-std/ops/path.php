<?php

// path.*: basename() and pathinfo($path, PATHINFO_EXTENSION).

return [
    'path.basename' => fn (array $a) => basename($a['path']),
    'path.pathinfo_extension' => fn (array $a) => pathinfo($a['path'], PATHINFO_EXTENSION),
];
