<?php

return [
    'settings.inspire' => '"Ang sining ng pagiging matalino ay ang sining ng pag-alam kung ano ang dapat kaligtaan."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ph',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => 'Pangkat ng %s',
    'account.emails.verification.title' => 'Pagkumpirma ng Account',
    'account.emails.verification.body' => 'ph.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Pagreset ng Password',
    'account.emails.recovery.body' => 'ph.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Paanyaya sa Pangkat ng %s sa %s',
    'account.emails.invitation.body' => 'ph.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Hindi alam',

    'countries' => include 'ph.countries.php',
    'continents' => include 'ph.continents.php',
];
