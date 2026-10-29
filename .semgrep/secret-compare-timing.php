<?php

// ruleid: php.appwrite.secret-compare-timing
return $challenge->getAttribute('code') === $otp;

// ruleid: php.appwrite.secret-compare-timing
if ($signature !== $expectedSignature) {
}

// ruleid: php.appwrite.secret-compare-timing
if ($providedSecret != $token->getAttribute('secret')) {
}

// ruleid: php.appwrite.secret-compare-timing
if (strcmp($hmac, $header) !== 0) {
}

// ok: php.appwrite.secret-compare-timing
return \hash_equals($challenge->getAttribute('code'), $otp);

// ok: php.appwrite.secret-compare-timing
if ($secret !== null) {
}

// ok: php.appwrite.secret-compare-timing
if ($code === '--') {
}

// ok: php.appwrite.secret-compare-timing
if ($error->getCode() === JWT::ERROR_TOKEN_EXPIRED) {
}
