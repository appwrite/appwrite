<?php

return [
    'settings.inspire' => '"Искусство быть мудрым — это искусство знать, чем можно пренебречь."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ru',
    'settings.direction' => 'ltr',

    'auth.emails.team' => 'Команда %s',
    'auth.emails.confirm.title' => 'Подтверждение аккаунта',
    'auth.emails.confirm.body' => 'ru.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Сброс пароля',
    'auth.emails.recovery.body' => 'ru.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Приглашение в команду %s по проекту %s',
    'auth.emails.invitation.body' => 'ru.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Неизвестно',

    'countries' => include 'ru.countries.php',
    'continents' => include 'ru.continents.php',
];
