<?php

return [
    'settings.inspire' => '"فن الحكمة هو فن معرفة ما يجب التغاضي عنه."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ar',
    'settings.direction' => 'rtl',

    // Service - Users
    'account.emails.team' => 'فريق %s',
    'account.emails.verification.title' => 'تأكيد الحساب',
    'account.emails.verification.body' => 'ar.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'إعادة تعيين كلمة المرور',
    'account.emails.recovery.body' => 'ar.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'دعوة لفريق %s في %s',
    'account.emails.invitation.body' => 'ar.email.auth.invitation.tpl',

    'locale.country.unknown' => 'مجهول',

    'countries' => include 'ar.countries.php',
    'continents' => include 'ar.continents.php',
];
