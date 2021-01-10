<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'hu',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s Csapat',
    'account.emails.verification.title' => 'Fiók megerősítése',
    'account.emails.verification.body' => 'hu.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Jelszó-visszaállítás',
    'account.emails.recovery.body' => 'hu.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Meghívás a %s csapatba %s',
    'account.emails.invitation.body' => 'hu.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Ismeretlen',

    'countries' => include 'hu.countries.php',
    'continents' => include 'hu.continents.php',
];
