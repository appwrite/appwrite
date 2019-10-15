<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'alb',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => 'Grup %s',
    'auth.emails.confirm.title' => 'Konfirmimi i llogarisë',
    'auth.emails.confirm.body' => 'alb.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Rivendosni fjalëkalimin',
    'auth.emails.recovery.body' => 'alb.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Ftesë në grup %s në %s',
    'auth.emails.invitation.body' => 'alb.email.auth.invitation.tpl',

    'locale.country.unknown' => 'I panjohur',

    'countries' => include 'alb.countries.php',
    'continents' => include 'alb.continents.php',
];
