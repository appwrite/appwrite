<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
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
