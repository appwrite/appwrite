<?php

return [
    'settings.inspire' => '"Seni menjadi bijak adalah seni mengetahui apa yang harus diabaikan."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'id',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => 'Tim %s',
    'account.emails.verification.title' => 'Konfirmasi Akun',
    'account.emails.verification.body' => 'id.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Reset Kata Sandi',
    'account.emails.recovery.body' => 'id.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Undangan ke Tim %s di %s',
    'account.emails.invitation.body' => 'id.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Tidak diketahui',

    'countries' => include 'id.countries.php',
    'continents' => include 'id.continents.php',
];
