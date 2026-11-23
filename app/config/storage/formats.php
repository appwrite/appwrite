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
    // Text and container formats sniff unreliably, so they are not listed.
    'signatures' => [
        'avif' => ['image/avif'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'gif' => ['image/gif'],
        'heic' => ['image/heic', 'image/heic-sequence', 'image/heif', 'image/heif-sequence'],
        'heif' => ['image/heic', 'image/heic-sequence', 'image/heif', 'image/heif-sequence'],
        'ico' => ['image/vnd.microsoft.icon', 'image/x-icon'],
        'jfif' => ['image/jpeg'],
        'jpe' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'jpg' => ['image/jpeg'],
        'pdf' => ['application/pdf'],
        'png' => ['image/png', 'image/apng'],
        'tif' => ['image/tiff'],
        'tiff' => ['image/tiff'],
        'webp' => ['image/webp'],
    ],
];
