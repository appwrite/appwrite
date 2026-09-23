<?php

// Content sniffing recognises the container a file is packed in, not the format packed
// inside it. A zip-based APK, JAR or KMZ is reported as a plain zip, a TrueType font as
// the generic SFNT container it shares with OpenType, and an AAR as an APK because both
// start with AndroidManifest.xml. The sniffed type is stored as the file's mimeType and
// echoed back as Content-Type on download, next to a Content-Disposition filename that
// carries the real extension, so a client that trusts the type saves the file under the
// wrong one.
//
// Where the extension is the only thing that can tell these formats apart, it wins. Only
// an ambiguous container type is replaced, and only ever with another binary type, so a
// file can never be promoted to something a browser would render.

return [
    'ambiguous' => [
        'application/octet-stream',
        'application/vnd.android.package-archive', // an AAR sniffs as an APK
        'application/vnd.ms-opentype', // the pre-RFC 8081 type for an OpenType font
        'application/zip',
        'font/sfnt', // the container TrueType and OpenType share
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
