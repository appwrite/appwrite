<?php

return [
    'settings.inspire' => '"എന്താണ് അവഗണിക്കേണ്ടതെന്ന് അറിയാനുള്ള കലയാണ് ബുദ്ധിമാനായിരിക്കുക"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'mal',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s ടീം',
    'auth.emails.confirm.title' => 'അക്കൗണ്ട് സ്ഥിരീകരണം ',
    'auth.emails.confirm.body' => 'mal.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'പാസ്‌വേഡ് പുന സജ്ജമാക്കുക ',
    'auth.emails.recovery.body' => 'mal.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'ക്ഷണ  %s ടീം  %s',
    'auth.emails.invitation.body' => 'mal.email.auth.invitation.tpl',

    'locale.country.unknown' => 'അജ്ഞാതം',

    'countries' => include 'mal.countries.php',
    'continents' => include 'mal.continents.php',
];
