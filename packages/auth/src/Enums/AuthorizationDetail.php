<?php

declare(strict_types=1);

namespace Utopia\Auth\Enums;

/**
 * The common fields of an RFC 9396 `authorization_details` entry (§2). `type`
 * is required and identifies the entry; the rest are optional and type-defined.
 * A type may also carry fields of its own beyond these. Backing the names with
 * an enum keeps them out of scattered string literals.
 */
enum AuthorizationDetail: string
{
    /** Identifier for the authorization details type (RFC 9396 §2). */
    case Type = 'type';

    /** Locations the requested access applies to (RFC 9396 §2). */
    case Locations = 'locations';

    /** Kinds of action to be taken at the resource (RFC 9396 §2). */
    case Actions = 'actions';

    /** Kinds of data being requested (RFC 9396 §2). */
    case Datatypes = 'datatypes';

    /** A specific resource the access applies to (RFC 9396 §2). */
    case Identifier = 'identifier';

    /** Types or levels of privilege being requested (RFC 9396 §2). */
    case Privileges = 'privileges';
}
