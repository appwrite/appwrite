<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'be',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => 'Каманда %s',
    'account.emails.verification.title' => 'Праверка ўліковага запісу',
    'account.emails.verification.body' => 'en.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Скід пароля',
    'account.emails.recovery.body' => 'en.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Запрашэнне да каманды %s y %s',
    'account.emails.invitation.body' => 'en.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Невядомы',

    'countries' => include 'en.countries.php',
    'continents' => include 'en.continents.php',
];
