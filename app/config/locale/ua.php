<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ua',
    'settings.direction' => 'rtl',

    'auth.emails.team' => '%s Команда',
    'auth.emails.confirm.title' => 'Підтвердження Акаунту' ,
    'auth.emails.confirm.body' => 'ua.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Скидання пароля',
    'auth.emails.recovery.body' => 'ua.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Запрошення до %s Команди у %s',
    'auth.emails.invitation.body' => 'ua.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Невідомо',

    'countries' => include 'ua.countries.php',
    'continents' => include 'ua.continents.php',
];
