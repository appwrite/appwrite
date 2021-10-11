<?php 

// Auth methods

return [
    'email-password' => [
        'name' => 'Email/Password',
        'key' => 'emailPassword',
        'icon' => '/images/users/email.png',
        'docs' => 'https://appwrite.io/docs/client/account?sdk=web#accountCreateSession',
        'enabled' => true,
    ],
    'magic-url' => [
        'name' => 'Magic URL',
        'key' => 'usersAuthMagicURL',
        'icon' => '/images/users/magic-url.png',
        'docs' => 'https://appwrite.io/docs/client/account?sdk=web#accountCreateMagicURLSession',
        'enabled' => true,
    ],
    'anonymous' => [
        'name' => 'Anonymous',
        'key' => 'anonymous',
        'icon' => '/images/users/anonymous.png',
        'docs' => 'https://appwrite.io/docs/client/account?sdk=web#accountCreateAnonymousSession',
        'enabled' => true,
    ],
    'invites' => [
        'name' => 'Invites',
        'key' => 'invites',
        'icon' => '/images/users/invites.png',
        'docs' => 'https://appwrite.io/docs/client/teams?sdk=web#teamsCreateMembership',
        'enabled' => true,
    ],
    'jwt' => [
        'name' => 'JWT',
        'key' => 'JWT',
        'icon' => '/images/users/jwt.png',
        'docs' => 'https://appwrite.io/docs/client/account?sdk=web#accountCreateJWT',
        'enabled' => true,
    ],
    'phone' => [
        'name' => 'Phone',
        'key' => 'phone',
        'icon' => '/images/users/phone.png',
        'docs' => '',
        'enabled' => false,
    ],
];