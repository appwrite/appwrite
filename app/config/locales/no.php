<?php

return [
    'settings.inspire' => '"Kunsten å være klok er kunsten å vite hva man skal overse."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'no',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s Team',
    'auth.emails.confirm.title' => 'Bekreftelse av konto',
    'auth.emails.confirm.body' => 'no.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Reset passord',
    'auth.emails.recovery.body' => 'no.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Invitasjon til %s Team på %s',
    'auth.emails.invitation.body' => 'no.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Ukjent',

    'countries' => include 'no.countries.php',
    'continents' => include 'no.continents.php',
];
