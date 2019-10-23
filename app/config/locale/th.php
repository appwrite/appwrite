<?php

return [
    'settings.inspire' => '"ศิลปะแห่งความฉลาดคือศิลปะแห่งการรู้ว่าจะมองข้ามอะไร"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'th',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s ทีม',
    'auth.emails.confirm.title' => 'ยืนยันบัญชี',
    'auth.emails.confirm.body' => 'th.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'รีเซ็ตรหัสผ่าน',
    'auth.emails.recovery.body' => 'th.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'ขอเชิญเข้าร่วม %s ทีมที่ %s',
    'auth.emails.invitation.body' => 'th.email.auth.invitation.tpl',

    'locale.country.unknown' => 'ไม่ทราบ',

    'countries' => include 'th.countries.php',
    'continents' => include 'th.continents.php',
];
