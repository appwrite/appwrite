<?php

return [
    'settings.inspire' => '"Sztuka bycia mądrym to sztuka wiedzieć, co przeoczyć."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'en',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => 'Zespół %s',
    'auth.emails.confirm.title' => 'Potwierdzenie konta',
    'auth.emails.confirm.body' => 'en.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Zresetowanie hasła',
    'auth.emails.recovery.body' => 'en.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Zaproszenie do zespołu %s - %s',
    'auth.emails.invitation.body' => 'en.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Nieznany',

    'countries' => include 'en.countries.php',
    'continents' => include 'en.continents.php',
];
