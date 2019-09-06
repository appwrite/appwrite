<?php

return [
    'settings.locale' => 'he',
    'settings.direction' => 'rtl',

    // Service - Users
    'auth.emails.team' => 'צוות %s',
    'auth.emails.confirm.title' => 'אימות חשבון',
    'auth.emails.confirm.body' => 'he.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'איפוס סיסמא',
    'auth.emails.recovery.body' => 'he.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'הזמנה לצוות של %s ב-%s',
    'auth.emails.invitation.body' => 'he.email.auth.invitation.tpl',

    'locale.country.unknown' => 'לא ידוע',

    'countries' => include 'he.countries.php',
    'continents' => include 'he.continents.php',
];
