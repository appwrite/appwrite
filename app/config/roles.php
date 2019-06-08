<?php

const ROLE_GUEST            = 0;
const ROLE_MEMBER           = 1;
const ROLE_ADMIN            = 2;
const ROLE_DEVELOPER        = 3;
const ROLE_OWNER            = 4;
const ROLE_APP              = 5;
const ROLE_SYSTEM           = 6;
const ROLE_ALL              = '*';

/**
 * public
 *
 * auth
 * account
 *
 * users.read
 * users.write
 *
 * teams.read
 * teams.write
 *
 * projects.read
 * projects.write
 *
 * documents.read
 * documents.write
 *
 * files.read
 * files.write
 * files.scan
 *
 * billing.currencies.read
 * billing.vaults.read
 * billing.vaults.write
 * billing.plans.read
 * billing.plans.write
 * billing.subscriptions.read
 * billing.subscriptions.write
 * billing.invoices.read
 *
 * health.read
 *
 * locale.read
 *
 * avatars.read
 *
 * auth.invite
 * auth.join
 * auth.leave
 */

$logged = [
    'public',
    'home',
    'console',
    'auth',
    'account',
    'teams.read',
    'teams.write',
    'documents.read',
    'documents.write',
    'files.read',
    'files.write',
    'billing.currencies.read',
    'billing.vaults.read',
    'billing.vaults.write',
    'billing.plans.read',
    'billing.subscriptions.read',
    'billing.subscriptions.write',
    'billing.invoices.read',
    'projects.read',
    'projects.write',
    'locale.read',
    'avatars.read',
    'health.read',
];

$admins = [
    'users.read',
    'users.write',
    'collections.read',
    'collections.write',
    'billing.plans.write',
];

return [
    ROLE_GUEST      => [
        'label' => 'Guest',
        'scopes' => [
            'public',
            'home',
            'console',
            'auth',
            'files.read',
            'locale.read',
            'avatars.read',
            'health.read',
            'billing.currencies.read',
        ]
    ],
    ROLE_MEMBER     => [
        'label' => 'Member',
        'scopes' => array_merge($logged, [])
    ],
    ROLE_ADMIN      => [
        'label' => 'Admin',
        'scopes' => array_merge($logged, $admins, [])
    ],
    ROLE_DEVELOPER  => [
        'label' => 'Developer',
        'scopes' => array_merge($logged, $admins, [])
    ],
    ROLE_OWNER      => [
        'label' => 'Owner',
        'scopes' => array_merge($logged, $admins, [])
    ],
    ROLE_APP        => [
        'label' => 'Application',
        'scopes' => ['public']
    ],
];