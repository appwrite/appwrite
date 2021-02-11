<?php

return [
    'settings.inspire' => '"Umjetnost mudrosti je umjetnost znanja o tome šta zanemariti."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ba',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s Tim',
    'account.emails.verification.title' => 'Verifikacija računa',
    'account.emails.verification.body' => 'ba.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Reset lozinke',
    'account.emails.recovery.body' => 'ba.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Pozivnica za %s Team na %s',
    'account.emails.invitation.body' => 'ba.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Nepoznat',

    'countries' => include 'ba.countries.php',
    'continents' => include 'ba.continents.php',
];
