<?php

declare(strict_types=1);

namespace Utopia\Cdn\Certificates;

/**
 * An HTTP validation token the certificate authority fetches to prove the
 * domain is controlled by whoever requested the certificate.
 *
 * Whatever currently serves the domain has to answer a request for `from`
 * with a redirect to `to`, which lets a certificate be issued before the
 * domain's traffic moves to the provider that requested it.
 */
final readonly class ValidationToken
{
    /**
     * @param string $domain Domain the token validates.
     * @param string $from URL the certificate authority requests, under `/.well-known/pki-validation/`.
     * @param string $to URL that serves the token, which `from` has to redirect to.
     */
    public function __construct(
        public string $domain,
        public string $from,
        public string $to,
    ) {
    }
}
