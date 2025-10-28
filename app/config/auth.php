<?php

// Auth methods

return [
    'email-password' => [
        'name' => 'Email/Password',
        'key' => 'emailPassword',
        'icon' => '/images/users/email.png',
        'docs' => 'https://appwrite.io/docs/references/cloud/client-web/account#accountCreateEmailPasswordSession',
        'enabled' => true,
    ],
    'magic-url' => [
        'name' => 'Magic URL',
        'key' => 'usersAuthMagicURL',
        'icon' => '/images/users/magic-url.png',
        'docs' => 'https://appwrite.io/docs/references/cloud/client-web/account#accountCreateMagicURLToken',
        'enabled' => true,
    ],
    'email-otp' => [
        'name' => 'Email (OTP)',
        'key' => 'emailOtp',
        'icon' => '/images/users/email.png',
        'docs' => 'https://appwrite.io/docs/references/cloud/client-web/account#accountCreateEmailToken',
        'enabled' => true,
    ],
    'anonymous' => [
        'name' => 'Anonymous',
        'key' => 'anonymous',
        'icon' => '/images/users/anonymous.png',
        'docs' => 'https://appwrite.io/docs/references/cloud/client-web/account#accountCreateAnonymousSession',
        'enabled' => true,
    ],
    'invites' => [
        'name' => 'Invites',
        'key' => 'invites',
        'icon' => '/images/users/invites.png',
        'docs' => 'https://appwrite.io/docs/client/teams?sdk=web-default#teamsCreateMembership',
        'enabled' => true,
    ],
    'jwt' => [
        'name' => 'JWT',
        'key' => 'JWT',
        'icon' => '/images/users/jwt.png',
        'docs' => 'https://appwrite.io/docs/client/account?sdk=web-default#accountCreateJWT',
        'enabled' => true,
    ],
    'phone' => [
        'name' => 'Phone',
        'key' => 'phone',
        'icon' => '/images/users/phone.png',
        'docs' => 'https://appwrite.io/docs/references/cloud/client-web/account#accountCreatePhoneToken',
        'enabled' => true,
    ],
];
