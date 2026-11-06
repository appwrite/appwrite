<?php

namespace Appwrite\Platform\Modules\S3\Requests;

use Appwrite\Extend\Exception as AppwriteException;

/**
 * Parser for CompleteMultipartUpload request bodies.
 *
 * S3 does not fix the order of the elements inside `<Part>`: AWS SDK for
 * Go v2 writes them alphabetically (`Checksum*`, `ETag`, `PartNumber`) while
 * other SDKs lead with `PartNumber`. Children are read by name, and the ones
 * Appwrite does not verify (per-part checksums) are ignored. A `<Part>`
 * without a part number or ETag is rejected rather than skipped, so a
 * completion can never silently drop a part and commit a truncated object.
 */
class CompleteMultipartUpload
{
    /**
     * @return array<int, string> ETags keyed by part number
     */
    public static function parts(string $body): array
    {
        if (\trim($body) === '') {
            return [];
        }

        $document = new \DOMDocument();
        $previous = \libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($body, LIBXML_NONET);
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }

        if (!$loaded || $document->doctype !== null) {
            throw self::malformed();
        }

        $parts = [];
        foreach ($document->getElementsByTagNameNS('*', 'Part') as $part) {
            $partNumber = '';
            $etag = '';
            foreach ($part->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                if ($child->localName === 'PartNumber') {
                    $partNumber = \trim($child->textContent);
                } elseif ($child->localName === 'ETag') {
                    $etag = \trim($child->textContent, " \t\n\r\0\x0B\"");
                }
            }

            if (!\ctype_digit($partNumber) || $etag === '') {
                throw self::malformed();
            }

            $parts[(int) $partNumber] = $etag;
        }

        return $parts;
    }

    private static function malformed(): AppwriteException
    {
        return new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, 'Malformed CompleteMultipartUpload XML. Each Part needs a PartNumber and an ETag.');
    }
}
