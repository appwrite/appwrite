<?php

return [
    'settings.inspire' => '"懂得取舍，方显睿智。"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'zh',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s 小组',
    'account.emails.verification.title' => '账户确认',
    'account.emails.verification.body' => 'zh.email.auth.confirm.tpl',
    'account.emails.recovery.title' => '重设密码',
    'account.emails.recovery.body' => 'zh.email.auth.recovery.tpl',
    'account.emails.invitation.title' => '邀请加入%s小组（%s）', // I used brackets to keep the order
    'account.emails.invitation.body' => 'zh.email.auth.invitation.tpl',

    'locale.country.unknown' => '未知',

    'countries' => include 'zh-cn.countries.php',
    'continents' => include 'zh-cn.continents.php',
];
