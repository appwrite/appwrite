<?php

return [
    'settings.inspire' => '"Sztuka bycia mądrym to sztuka wiedzieć, co przeoczyć."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'pl',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => 'Zespół %s',
    'account.emails.verification.title' => 'Potwierdzenie konta',
    'account.emails.verification.body' => 'pl.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Zresetowanie hasła',
    'account.emails.recovery.body' => 'pl.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Zaproszenie do zespołu %s - %s',
    'account.emails.invitation.body' => 'pl.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Nieznany',

    'countries' => include 'pl.countries.php',
    'continents' => include 'pl.continents.php',
];
