<?php

return [
    'settings.inspire' => '"Kesenian sing wicaksana yaiku seni sing ngerti apa sing kudu dilalekake."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'jv',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => 'Tim %s',
    'auth.emails.confirm.title' => 'Konfirmasi akun',
    'auth.emails.confirm.body' => 'jv.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Reset Sandi',
    'auth.emails.recovery.body' => 'jv.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Undangan menyang %s Tim ing %s',
    'auth.emails.invitation.body' => 'jv.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Ora dingerteni',

    'countries' => include 'jv.countries.php',
    'continents' => include 'jv.continents.php',
];
