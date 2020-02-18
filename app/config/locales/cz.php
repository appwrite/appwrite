<?php

return [
    'settings.inspire' => '"Umění moudrosti je umění vědět, co přehlédnout."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'cz',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s tým',
    'account.emails.verification.title' => 'Potvrzení účtu',
    'account.emails.verification.body' => 'cz.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Resetovat heslo',
    'account.emails.recovery.body' => 'cz.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Pozvánka do týmu% s na% s',
    'account.emails.invitation.body' => 'cz.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Neznámý',

    'countries' => include 'cz.countries.php',
    'continents' => include 'cz.continents.php',
];
