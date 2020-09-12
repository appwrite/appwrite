<?php

return [
    'settings.inspire' => '"De kunst om wijs te zijn is de kunst om te weten wat over het hoofd gezien moet worden."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'nl',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s Team',
    'account.emails.verification.title' => 'Account Bevestiging',
    'account.emails.verification.body' => 'nl.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Wachtwoord herstellen',
    'account.emails.recovery.body' => 'nl.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Uitnodiging naar %s Team voor %s',
    'account.emails.invitation.body' => 'nl.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Onbekend',

    'countries' => include 'nl.countries.php',
    'continents' => include 'nl.continents.php',
];
