<?php

return [
    'settings.inspire' => '"De kunst om wijs te zijn is de kunst om te weten wat over het hoofd gezien moet worden."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'nl',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s Team',
    'auth.emails.confirm.title' => 'Account Bevestiging',
    'auth.emails.confirm.body' => 'nl.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Wachtwoord herstellen',
    'auth.emails.recovery.body' => 'nl.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Uitnodiging naar %s Team voor %s',
    'auth.emails.invitation.body' => 'nl.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Onbekend',

    'countries' => include 'nl.countries.php',
    'continents' => include 'nl.continents.php',
];