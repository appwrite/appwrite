<?php

namespace Appwrite\Network;

use InvalidArgumentException;

/**
 * A hostname that gave no address: it does not exist, or no DNS server answered. The
 * lookup cannot tell the two apart, so this says nothing about the host itself. It is
 * an InvalidArgumentException so a check that only wants to refuse the host still can.
 */
final class HostnameNotResolved extends InvalidArgumentException
{
}
