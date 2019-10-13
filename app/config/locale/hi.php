<?php

return [
    'settings.inspire' => '"क्या अनदेखा करना चाहिए, यह जानने की कला ही बुद्धिमान होने की कला है | "', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'hi',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s टीम',
    'auth.emails.confirm.title' => 'अकाउंट प्रमाणीकरण',
    'auth.emails.confirm.body' => 'hi.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'पासवर्ड रिसेट ',
    'auth.emails.recovery.body' => 'hi.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => '%s टीम को %s पर आमंत्रण',
    'auth.emails.invitation.body' => 'hi.email.auth.invitation.tpl',

    'locale.country.unknown' => 'अज्ञात',

    'countries' => include 'hi.countries.php',
    'continents' => include 'hi.continents.php',
];
