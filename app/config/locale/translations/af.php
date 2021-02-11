<?php

return [
    'settings.inspire' => '"Wie nie waag nie, sal nie wen nie."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'af',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s span',
    'account.emails.verification.title' => 'Profiel Bevestiging',
    'account.emails.verification.body' => 'af.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Verander Wagwoord',
    'account.emails.recovery.body' => 'af.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Uitnodiging na %s span by %s',
    'account.emails.invitation.body' => 'af.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Onbekend',

    'countries' => include 'af.countries.php',
    'continents' => include 'af.continents.php',
];
