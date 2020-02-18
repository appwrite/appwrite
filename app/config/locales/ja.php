<?php

return [
    'settings.inspire' => '"賢明になる術は何を捨てるべきかを心得る術である。"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ja',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s チーム',
    'account.emails.verification.title' => 'アカウント確認のお願い',
    'account.emails.verification.body' => 'ja.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'パスワード再設定のお願い',
    'account.emails.recovery.body' => 'ja.email.auth.recovery.tpl',
    'account.emails.invitation.title' => '%s チーム（%s プロジェクト）への招待',
    'account.emails.invitation.body' => 'ja.email.auth.invitation.tpl',

    'locale.country.unknown' => '不明',

    'countries' => include 'ja.countries.php',
    'continents' => include 'ja.continents.php',
];
