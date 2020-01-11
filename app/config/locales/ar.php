<?php

return [
    'settings.inspire' => '"فن الحكمة هو فن معرفة ما يجب التغاضي عنه."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'ar',
    'settings.direction' => 'rtl',

    // Service - Users
    'auth.emails.team' => 'فريق %s',
    'auth.emails.confirm.title' => 'تأكيد الحساب',
    'auth.emails.confirm.body' => 'ar.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'إعادة تعيين كلمة المرور',
    'auth.emails.recovery.body' => 'ar.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'دعوة لفريق %s في %s',
    'auth.emails.invitation.body' => 'ar.email.auth.invitation.tpl',

    'locale.country.unknown' => 'مجهول',

    'countries' => include 'ar.countries.php',
    'continents' => include 'ar.continents.php',
];
