<?php

return [
    'settings.inspire' => '"Ang sining ng pagiging matalino ay ang sining ng pag-alam kung ano ang dapat kaligtaan."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ph',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => 'Pangkat ng %s',
    'auth.emails.confirm.title' => 'Pagkumpirma ng Account',
    'auth.emails.confirm.body' => 'ph.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Pagreset ng Password',
    'auth.emails.recovery.body' => 'ph.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Paanyaya sa Pangkat ng %s sa %s',
    'auth.emails.invitation.body' => 'ph.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Hindi alam',

    'countries' => include 'ph.countries.php',
    'continents' => include 'ph.continents.php',
];
