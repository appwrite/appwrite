<?php

return [
    'settings.inspire' => '"Bilge olma sanatı, neyi ihmal edeceğini bilme sanatıdır."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'tr',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s Takımı',
    'auth.emails.confirm.title' => 'Hesap Doğrulama',
    'auth.emails.confirm.body' => 'tr.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Şifre Sıfırlama',
    'auth.emails.recovery.body' => 'tr.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => '%s takımına davet %s',
    'auth.emails.invitation.body' => 'tr.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Bilinmiyen',

    'countries' => include 'tr.countries.php',
    'continents' => include 'tr.continents.php',
];
