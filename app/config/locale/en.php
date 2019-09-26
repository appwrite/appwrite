<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."',
    'settings.locale' => 'en',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s Team',
    'auth.emails.confirm.title' => 'Account Confirmation',
    'auth.emails.confirm.body' => 'en.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Password Reset',
    'auth.emails.recovery.body' => 'en.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Invitation to %s Team at %s',
    'auth.emails.invitation.body' => 'en.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Unknown',

    'countries' => include 'en.countries.php',
    'continents' => include 'en.continents.php',
];
