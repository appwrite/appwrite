<?php

return [
    'settings.inspire' => '"지혜롭게 되는 묘책은 그동안 간과했던 것을 알아내는 것에 있다"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ko',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s 팀',
    'auth.emails.confirm.title' => '계정 확인',
    'auth.emails.confirm.body' => 'ko.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => '비밀번호 재설정',
    'auth.emails.recovery.body' => 'ko.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => '%s 팀(%s 프로젝트)에 합류 초대',
    'auth.emails.invitation.body' => 'ko.email.auth.invitation.tpl',

    'locale.country.unknown' => '알려지지 않은',

    'countries' => include 'ko.countries.php',
    'continents' => include 'ko.continents.php',
];
