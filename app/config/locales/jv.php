<?php

return [
    'settings.inspire' => '"Kesenian sing wicaksana yaiku seni sing ngerti apa sing kudu dilalekake."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'jv',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => 'Tim %s',
    'account.emails.verification.title' => 'Konfirmasi akun',
    'account.emails.verification.body' => 'jv.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Reset Sandi',
    'account.emails.recovery.body' => 'jv.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Undangan menyang %s Tim ing %s',
    'account.emails.invitation.body' => 'jv.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Ora dingerteni',

    'countries' => include 'jv.countries.php',
    'continents' => include 'jv.continents.php',
];
