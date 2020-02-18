<?php

return [
    'settings.inspire' => '"Kunstin om at vera vís er at vita hvat man skal misrøkja."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'fo',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s Lið',
    'account.emails.verification.title' => 'Vátta brúkari',
    'account.emails.verification.body' => 'fo.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Glómt passord',
    'account.emails.recovery.body' => 'fo.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Innbjóðing til %s Lið hjá %s',
    'account.emails.invitation.body' => 'fo.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Ókjent',

    'countries' => include 'fo.countries.php',
    'continents' => include 'fo.continents.php',
];
