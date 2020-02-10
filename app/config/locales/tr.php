<?php

return [
    'settings.inspire' => '"Bilge olma sanatı, neyi ihmal edeceğini bilme sanatıdır."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'tr',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s Takımı',
    'account.emails.verification.title' => 'Hesap Doğrulama',
    'account.emails.verification.body' => 'tr.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Şifre Sıfırlama',
    'account.emails.recovery.body' => 'tr.email.auth.recovery.tpl',
    'account.emails.invitation.title' => '%s takımına davet %s',
    'account.emails.invitation.body' => 'tr.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Bilinmeyen',

    'countries' => include 'tr.countries.php',
    'continents' => include 'tr.continents.php',
];
