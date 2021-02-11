<?php

return [
    'settings.inspire' => '"지혜롭게 되는 묘책은 그동안 간과했던 것을 알아내는 것에 있다"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ko',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s 팀',
    'account.emails.verification.title' => '계정 확인',
    'account.emails.verification.body' => 'ko.email.auth.confirm.tpl',
    'account.emails.recovery.title' => '비밀번호 재설정',
    'account.emails.recovery.body' => 'ko.email.auth.recovery.tpl',
    'account.emails.invitation.title' => '%s 팀(%s 프로젝트)에 합류 초대',
    'account.emails.invitation.body' => 'ko.email.auth.invitation.tpl',

    'locale.country.unknown' => '알려지지 않은',

    'countries' => include 'ko.countries.php',
    'continents' => include 'ko.continents.php',
];
