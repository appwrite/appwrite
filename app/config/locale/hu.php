<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'hu',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s Csapat',
    'auth.emails.confirm.title' => 'Fiók megerősítés',
    'auth.emails.confirm.body' => 'hu.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Jelszó Visszaállítás',
    'auth.emails.recovery.body' => 'hu.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Meghívás a %s Csapathoz %s',
    'auth.emails.invitation.body' => 'hu.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Ismeretlen',

    'countries' => include 'hu.countries.php',
    'continents' => include 'hu.continents.php',
];
