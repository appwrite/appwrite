<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ru',
    'settings.direction' => 'rtl',

    'auth.emails.team' => '%s Команда',
    'auth.emails.confirm.title' => 'Подтверждение Аккаунта',
    'auth.emails.confirm.body' => 'ru.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Сброс Пароля',
    'auth.emails.recovery.body' => 'ru.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Приглашение в %s Команду в %s',
    'auth.emails.invitation.body' => 'ru.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Неизвестно',

    'countries' => include 'ru.countries.php',
    'continents' => include 'ru.continents.php',
];
