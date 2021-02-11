<?php

return [
    'settings.inspire' => '"Vishet är konsten att förstå vad man ska förbise."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'sv',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s-teamet',
    'account.emails.verification.title' => 'Kontobekräftelse',
    'account.emails.verification.body' => 'sv.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Ändra lösenord',
    'account.emails.recovery.body' => 'sv.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Inbjudan till %s-teamet i %s',
    'account.emails.invitation.body' => 'sv.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Okänt',

    'countries' => include 'sv.countries.php',
    'continents' => include 'sv.continents.php',
];
