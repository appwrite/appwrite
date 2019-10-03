<?php

return [
    'settings.inspire' => '"Umění moudrosti je umění vědět, co přehlédnout."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'cz',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s tým',
    'auth.emails.confirm.title' => 'Potvrzení účtu',
    'auth.emails.confirm.body' => 'cz.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Resetovat heslo',
    'auth.emails.recovery.body' => 'cz.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Pozvánka do týmu% s na% s',
    'auth.emails.invitation.body' => 'cz.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Neznámý',

    'countries' => include 'cz.countries.php',
    'continents' => include 'cz.continents.php',
];
