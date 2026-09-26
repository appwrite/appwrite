<?php

declare(strict_types=1);

namespace Utopia\Storage;

/**
 * Amazon S3 canned ACL grants applied to written objects.
 */
enum Acl: string
{
    case Private = 'private';

    case PublicRead = 'public-read';

    case PublicReadWrite = 'public-read-write';

    case AuthenticatedRead = 'authenticated-read';
}
