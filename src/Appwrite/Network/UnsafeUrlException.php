<?php

namespace Appwrite\Network;

/**
 * Thrown when a URL fails server-side fetch safety checks
 * (scheme, public suffix membership, or publicly-routable resolution).
 */
class UnsafeUrlException extends \RuntimeException
{
}
