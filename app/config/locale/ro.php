<?php

return [
    'settings.inspire' => '"Arta de a fi înţelept este arta de a intui ce trebuie trecut cu vederea."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ro',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s Echipa',
    'auth.emails.confirm.title' => 'Confirmă Contul',
    'auth.emails.confirm.body' => 'ro.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Resetează Parola',
    'auth.emails.recovery.body' => 'ro.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Invitație în Echipa %s la %s',
    'auth.emails.invitation.body' => 'ro.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Necunoscut',

    'countries' => include 'ro.countries.php',
    'continents' => include 'ro.continents.php',
];
