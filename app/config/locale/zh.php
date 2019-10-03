<?php

return [
    'settings.inspire' => '"懂得取舍，方显睿智。"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'zh',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s 小组',
    'auth.emails.confirm.title' => '账户确认',
    'auth.emails.confirm.body' => 'zh.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => '重设密码',
    'auth.emails.recovery.body' => 'zh.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => '邀请加入%s小组（%s）', // I used brackets to keep the order
    'auth.emails.invitation.body' => 'zh.email.auth.invitation.tpl',

    'locale.country.unknown' => '未知',

    'countries' => include 'zh.countries.php',
    'continents' => include 'zh.continents.php',
];
