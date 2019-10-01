<?php

return [
    'settings.inspire' => '"Die Kunst, weise zu sein, ist die Kunst, zu wissen, was zu übersehen ist."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'de',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s Team',
    'auth.emails.confirm.title' => 'Accountbestätigung',
    'auth.emails.confirm.body' => 'de.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Passwort zurücksetzen',
    'auth.emails.recovery.body' => 'de.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Einladung zum %s Team bei %s',
    'auth.emails.invitation.body' => 'de.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Unbekannt',

    'countries' => include 'de.countries.php',
    'continents' => include 'de.continents.php',
];
