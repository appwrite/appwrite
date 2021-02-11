<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'fi',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s Tiimi',
    'account.emails.verification.title' => 'Tilin Vahvistus',
    'account.emails.verification.body' => 'fi.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Salasanan Nollaus',
    'account.emails.recovery.body' => 'fi.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Kutsu %s Tiimiin %s',
    'account.emails.invitation.body' => 'fi.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Unknown',

    'countries' => include 'fi.countries.php',
    'continents' => include 'fi.continents.php',
];
