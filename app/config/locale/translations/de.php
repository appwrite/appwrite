<?php

return [
    'settings.inspire' => '"Die Kunst, weise zu sein, ist die Kunst, zu wissen, was zu übersehen ist."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'de',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s Team',
    'account.emails.verification.title' => 'Accountbestätigung',
    'account.emails.verification.body' => 'de.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Passwort zurücksetzen',
    'account.emails.recovery.body' => 'de.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Einladung zum %s Team bei %s',
    'account.emails.invitation.body' => 'de.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Unbekannt',

    'countries' => include 'de.countries.php',
    'continents' => include 'de.continents.php',
];
