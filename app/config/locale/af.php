<?php

return [
    'settings.inspire' => '"Wie nie waag nie, sal nie wen nie."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'af',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s span',
    'auth.emails.confirm.title' => 'Profiel Bevestiging',
    'auth.emails.confirm.body' => 'af.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Verander Wagwoord',
    'auth.emails.recovery.body' => 'af.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Uitnodiging na %s span by %s',
    'auth.emails.invitation.body' => 'af.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Onbekend',

    'countries' => include 'af.countries.php',
    'continents' => include 'af.continents.php',
];
