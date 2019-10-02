<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'id',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => 'Tim %s',
    'auth.emails.confirm.title' => 'Konfirmasi Akun',
    'auth.emails.confirm.body' => 'id.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Reset Kata Sandi',
    'auth.emails.recovery.body' => 'id.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Undangan ke Tim %s di %s',
    'auth.emails.invitation.body' => 'id.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Tidak diketahui',

    'countries' => include 'id.countries.php',
    'continents' => include 'id.continents.php',
];
