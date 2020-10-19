<?php

return [
    'settings.inspire' => '"懂得取捨，方顯睿智。"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'zh-tw',
    'settings.direction' => 'ltr',
   
    // Service - Users
    'account.emails.team' => '%s 小組',
    'account.emails.verification.title' => '帳戶確認',
    'account.emails.verification.body' => 'zh-tw.email.auth.confirm.tpl',
    'account.emails.recovery.title' => '重設密碼',
    'account.emails.recovery.body' => 'zh.email.auth.recovery.tpl',
    'account.emails.invitation.title' => '邀請加入%s小組（%s）',
    'account.emails.invitation.body' => 'zh-tw.email.auth.invitation.tpl',
    'locale.country.unknown' => '未知',
    'countries' => include 'zh-tw.countries.php',
    'continents' => include 'zh-tw.continents.php',
];
