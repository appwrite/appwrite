<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'lk',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s Team',
    'auth.emails.confirm.title' => 'Account Confirmation',
    'auth.emails.confirm.body' => 'lk.email.auth.confirm',
    'auth.emails.recovery.title' => 'Password Reset',
    'auth.emails.recovery.body' => 'lk.email.auth.recovery',
    'auth.emails.invitation.title' => 'Invitation to %s Team at %s',
    'auth.emails.invitation.body' => 'lk.email.auth.invitation',

    'locale.country.unknown' => 'Unknown',

    'countries' => include 'lk.countries.php',
    'continents' => include 'lk.continents.php',
];
