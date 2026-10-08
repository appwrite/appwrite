<?php

namespace Appwrite\Auth\Passkey;

use Utopia\Auth\Passkeys\Origin;
use Utopia\Domains\Validator\RegistrableDomain;
use Utopia\Validator\AnyOf;
use Utopia\Validator\WhiteList;

/**
 * The relying party the console project signs in with: bound to the console's own host, so it never
 * changes after passkeys exist, plus any extra origins on that host such as preview consoles.
 */
class Console
{
    /**
     * Passkey auth settings for the console project, with passkeys off when the URL cannot be a relying party.
     *
     * @param string $url the console's origin, such as https://cloud.appwrite.io
     * @param string $origins comma-separated extra origins on the same host or its subdomains
     * @return array{passkey: bool, passkeyRpId: string, passkeyOrigins: array<string>}
     */
    public static function getAuths(string $url, string $origins): array
    {
        $rpId = \parse_url($url, PHP_URL_HOST) ?: '';
        $normalized = [];

        if (new AnyOf([new WhiteList([Origin::LOCALHOST], true), new RegistrableDomain()])->isValid($rpId)) {
            $origin = new Origin($rpId);
            foreach ([$url, ...\explode(',', $origins)] as $candidate) {
                $value = $origin->normalize(\trim($candidate));
                if ($value !== null) {
                    $normalized[] = $value;
                }
            }
        }

        $normalized = \array_values(\array_unique($normalized));

        return [
            'passkey' => $normalized !== [],
            'passkeyRpId' => $normalized === [] ? '' : $rpId,
            'passkeyOrigins' => $normalized,
        ];
    }
}
