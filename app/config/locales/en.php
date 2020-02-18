<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'en',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s Team',
    'account.emails.verification.title' => 'Account Verification',
    'account.emails.verification.body' => 'en.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Password Reset',
    'account.emails.recovery.body' => 'en.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Invitation to %s Team at %s',
    'account.emails.invitation.body' => 'en.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Unknown',

    'countries' => include 'en.countries.php',
    'continents' => include 'en.continents.php',
];
