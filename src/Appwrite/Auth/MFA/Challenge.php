<?php

namespace Appwrite\Auth\MFA;

use Utopia\Database\Document;

abstract class Challenge
{
    abstract public static function verify(Document $user, string $otp): bool;
    abstract public static function challenge(Document $challenge, Document $user, string $otp): bool;
}
