<?php

declare(strict_types=1);

namespace Utopia\Auth\Enums;

/**
 * JOSE header parameters (RFC 7515 §4) used by this library's JWS tokens.
 */
enum Header: string
{
    /** Media type of the token (RFC 7515 §4.1.9). */
    case Type = 'typ';

    /** Signing algorithm (RFC 7515 §4.1.1). */
    case Algorithm = 'alg';

    /** Key identifier (RFC 7515 §4.1.4). */
    case KeyId = 'kid';
}
