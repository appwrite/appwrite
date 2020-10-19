<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ua',
    'settings.direction' => 'ltr',

    'account.emails.team' => 'Команда %s',
    'account.emails.verification.title' => 'Підтвердження Акаунту' ,
    'account.emails.verification.body' => 'ua.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Скидання пароля',
    'account.emails.recovery.body' => 'ua.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Запрошення до Команди %s у %s',
    'account.emails.invitation.body' => 'ua.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Невідомо',

    'countries' => include 'ua.countries.php',
    'continents' => include 'ua.continents.php',
];
