<?php

return [
    'settings.inspire' => '"Seni menjadi pandai adalah seni mengetahui apa yang dilihatnya."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'my',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s Team',
    'auth.emails.confirm.title' => 'Pengesahan akaun',
    'auth.emails.confirm.body' => 'my.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Tetapkan semula kata laluan',
    'auth.emails.recovery.body' => 'my.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Undangan ke dalam kumpulan %s di %s',
    'auth.emails.invitation.body' => 'my.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Tidak Diketahui',

    'countries' => include 'my.countries.php',
    'continents' => include 'my.continents.php',
];
