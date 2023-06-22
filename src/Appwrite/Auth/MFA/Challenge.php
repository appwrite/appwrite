<?php

namespace Appwrite\Auth\MFA;

use Utopia\Database\Document;

abstract class Challenge
{
    abstract static function verify(Document $user, string $otp): bool;
    abstract static function challenge(Document $challenge, Document $user, string $otp): bool;
}
