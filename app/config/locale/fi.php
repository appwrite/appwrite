<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'fi',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s Tiimi',
    'auth.emails.confirm.title' => 'Tilin Vahvistus',
    'auth.emails.confirm.body' => 'en.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Salasanan Nollaus',
    'auth.emails.recovery.body' => 'en.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Kutsu %s Tiimiin %s',
    'auth.emails.invitation.body' => 'en.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Unknown',

    'countries' => include 'fi.countries.php',
    'continents' => include 'fi.continents.php',
];