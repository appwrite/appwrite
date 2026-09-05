<?php

namespace Appwrite\Auth\OIDC;

/**
 * The provider's JWKS endpoint could not be fetched or returned an invalid
 * document. Distinct from VerificationException because it signals a provider
 * outage rather than a bad token.
 */
class JwksException extends \Exception
{
}
