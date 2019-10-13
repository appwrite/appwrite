<?php

return [
    'settings.inspire' => '"Vishet är konsten att förstå vad man ska förbise."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'sv',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s-teamet',
    'auth.emails.confirm.title' => 'Kontobekräftelse',
    'auth.emails.confirm.body' => 'sv.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Ändra lösenord',
    'auth.emails.recovery.body' => 'sv.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Inbjudan till %s-teamet i %s',
    'auth.emails.invitation.body' => 'sv.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Okänt',

    'countries' => include 'sv.countries.php',
    'continents' => include 'sv.continents.php',
];
