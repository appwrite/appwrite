<?php

declare(strict_types=1);

namespace Utopia\Auth\Enums;

/**
 * Names of the JWT claims this library issues and verifies.
 *
 * The standard registered claims (RFC 7519 §4.1) plus the OAuth2 (RFC 9068)
 * and OpenID Connect claims used by the concrete issuers. Backing the names
 * with an enum keeps them in one place instead of scattered string literals.
 */
enum Claim: string
{
    /** Issuer (RFC 7519 §4.1.1). */
    case Issuer = 'iss';

    /** Subject (RFC 7519 §4.1.2). */
    case Subject = 'sub';

    /** Audience (RFC 7519 §4.1.3). */
    case Audience = 'aud';

    /** Expiration time (RFC 7519 §4.1.4). */
    case Expiration = 'exp';

    /** Not before (RFC 7519 §4.1.5). */
    case NotBefore = 'nbf';

    /** Issued at (RFC 7519 §4.1.6). */
    case IssuedAt = 'iat';

    /** JWT ID (RFC 7519 §4.1.7). */
    case JwtId = 'jti';

    /** Client the token was issued to (RFC 9068 §2.2). */
    case ClientId = 'client_id';

    /** Time the end-user authenticated (OpenID Connect Core 1.0 §2). */
    case AuthTime = 'auth_time';

    /** Space-delimited granted scopes (RFC 9068 §2.2.3). */
    case Scope = 'scope';

    /** Authentication request nonce (OpenID Connect Core 1.0 §2). */
    case Nonce = 'nonce';

    /** Access token hash (OpenID Connect Core 1.0 §3.1.3.6). */
    case AccessTokenHash = 'at_hash';

    /** Authorization code hash (OpenID Connect Core 1.0 §3.3.2.11). */
    case CodeHash = 'c_hash';
}
