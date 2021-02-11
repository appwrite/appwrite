<?php

return [
    'settings.inspire' => '"Изкуството да бъдеш мъдър е изкуството да знаеш какво да пренебрегнеш."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'bg',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s Екип',
    'account.emails.verification.title' => 'Потвърждаване на профила',
    'account.emails.verification.body' => 'bg.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Възстановяване на парола',
    'account.emails.recovery.body' => 'bg.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Покана към %s екипа при %s',
    'account.emails.invitation.body' => 'bg.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Неизвестно',

    'countries' => include 'bg.countries.php',
    'continents' => include 'bg.continents.php',
];
