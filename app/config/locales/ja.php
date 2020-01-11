<?php

return [
    'settings.inspire' => '"賢明になる術は何を捨てるべきかを心得る術である。"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ja',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s チーム',
    'auth.emails.confirm.title' => 'アカウント確認のお願い',
    'auth.emails.confirm.body' => 'ja.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'パスワード再設定のお願い',
    'auth.emails.recovery.body' => 'ja.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => '%s チーム（%s プロジェクト）への招待',
    'auth.emails.invitation.body' => 'ja.email.auth.invitation.tpl',

    'locale.country.unknown' => '不明',

    'countries' => include 'ja.countries.php',
    'continents' => include 'ja.continents.php',
];
