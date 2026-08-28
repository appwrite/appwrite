<?php

namespace Appwrite\Storage;

use enshrined\svgSanitize\Sanitizer;

class Svg
{
    /**
     * Sanitize an SVG so it is safe to hand to a raster engine.
     *
     * On top of the XSS stripping the underlying library does (scripts, event
     * handlers, javascript: hrefs, DOCTYPE/external entities), this tightens the
     * href policy: the library allows http(s) hrefs, which lets an <image> or
     * <use> pull a remote or local resource during rasterization (SSRF / local
     * file read). Here only in-document fragments (#id) and inline data:image
     * URIs are kept; every other href is removed.
     *
     * @return string|null the cleaned SVG, or null if it could not be parsed
     */
    public static function sanitize(string $svg): ?string
    {
        $sanitizer = new class () extends Sanitizer {
            protected function isHrefSafeValue($value): bool
            {
                if ($value === '' || $value === null) {
                    return true;
                }

                if ($value[0] === '#') {
                    return true;
                }

                return \str_starts_with($value, 'data:image/') || \str_starts_with($value, 'data:img/');
            }
        };

        $sanitizer->removeRemoteReferences(true);
        $clean = $sanitizer->sanitize($svg);

        return $clean === false ? null : $clean;
    }
}
