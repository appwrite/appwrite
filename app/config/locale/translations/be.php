<?php

return [
    'settings.inspire' => '"Мастацтва быць мудрым - гэта мастацтва ведаць, на што нельга звярнуць увагу."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'be',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => 'Каманда %s',
    'account.emails.verification.title' => 'Праверка ўліковага запісу',
    'account.emails.verification.body' => 'be.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Скід пароля',
    'account.emails.recovery.body' => 'be.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Запрашэнне да каманды %s y %s',
    'account.emails.invitation.body' => 'be.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Невядомы',

    'countries' => include 'be.countries.php',
    'continents' => include 'be.continents.php',
];
