<?php

namespace Appwrite\Avatars;

use Appwrite\URL\URL;
use DOMDocument;
use DOMElement;

/**
 * Picks the icon URL declared by a page.
 */
class Favicon
{
    /**
     * Absolute icon URL and extension for the page at $pageUrl.
     * Relative links and the /favicon.ico fallback are resolved against that URL.
     *
     * @return array{0: string, 1: string}
     */
    public static function locate(string $html, string $pageUrl): array
    {
        $doc = new DOMDocument();
        $doc->strictErrorChecking = false;
        if (!empty($html)) {
            @$doc->loadHTML($html);
        }

        $links = $doc->getElementsByTagName('link');
        $outputHref = '';
        $outputExt = '';
        $space = 0;

        foreach ($links as $link) { /* @var $link DOMElement */
            $href = $link->getAttribute('href');
            $rel = $link->getAttribute('rel');
            $sizes = $link->getAttribute('sizes');
            $absolute = URL::resolveLocation($pageUrl, $href);

            switch (\strtolower($rel)) {
                case 'icon':
                case 'shortcut icon':
                    $ext = \pathinfo(\parse_url($absolute, PHP_URL_PATH), PATHINFO_EXTENSION);

                    switch ($ext) {
                        case 'svg':
                            // SVG icons are prioritized by assigning the maximum possible value.
                            $space = PHP_INT_MAX;
                            $outputHref = $absolute;
                            $outputExt = $ext;
                            break;
                        case 'ico':
                        case 'png':
                        case 'jpg':
                        case 'jpeg':
                            $size = \explode('x', \strtolower($sizes));

                            $sizeWidth = (int) $size[0];
                            $sizeHeight = (int) ($size[1] ?? 0);

                            if (($sizeWidth * $sizeHeight) >= $space) {
                                $space = $sizeWidth * $sizeHeight;
                                $outputHref = $absolute;
                                $outputExt = $ext;
                            }

                            break;
                    }

                    break;
            }
        }

        if (empty($outputHref) || empty($outputExt)) {
            $outputHref = URL::resolveLocation($pageUrl, '/favicon.ico');
            $outputExt = 'ico';
        }

        return [$outputHref, $outputExt];
    }
}
