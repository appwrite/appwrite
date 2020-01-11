<?php

return [
    'settings.inspire' => '"懂得取舍，方顯睿智。"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'zh-tw',
    'settings.direction' => 'ltr',
   
    // Service - Users
    'auth.emails.team' => '%s 小組',
    'auth.emails.confirm.title' => '賬戶確認',
    'auth.emails.confirm.body' => 'zh-tw.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => '重設密碼',
    'auth.emails.recovery.body' => 'zh.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => '邀請加入%s小組（%s）',
    'auth.emails.invitation.body' => 'zh-tw.email.auth.invitation.tpl',
    'locale.country.unknown' => '未知',
    'countries' => include 'zh-tw.countries.php',
    'continents' => include 'zh-tw.continents.php',
];