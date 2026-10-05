<?php

// Sniffing reports the container a file is packed in, not the format inside it: a
// zip-based APK or JAR comes back as a plain zip, a TrueType font as a bare SFNT.
// Every type below is binary and absent from mimes.php, so none can be rendered.

return [
    'ambiguous' => [
        'application/octet-stream',
        'application/vnd.android.package-archive', // an AAR sniffs as an APK
        'application/vnd.ms-opentype',
        'application/zip',
        'font/sfnt',
    ],
    'extensions' => [
        '3mf' => 'model/3mf',
        'aar' => 'application/java-archive',
        'apk' => 'application/vnd.android.package-archive',
        'jar' => 'application/java-archive',
        'kmz' => 'application/vnd.google-earth.kmz',
        'msix' => 'application/msix',
        'otf' => 'font/otf',
        'ttf' => 'font/ttf',
        'usdz' => 'model/vnd.usdz+zip',
        'war' => 'application/java-archive',
        'xpi' => 'application/x-xpinstall',
    ],
];
