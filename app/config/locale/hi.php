<?php

return [
    'settings.inspire' => '"यह जान लेना की क्या अनदेखा किया जा सकता है, ही बुद्धिमता का प्रतीक है |"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'hi',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s टीम',
    'auth.emails.confirm.title' => 'अकाउंट  कन्फर्मेशन ',
    'auth.emails.confirm.body' => 'hi.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'पासवर्ड  रिसेट ',
    'auth.emails.recovery.body' => 'hi.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'इनविटेशन  %s टीम  %s',
    'auth.emails.invitation.body' => 'hi.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Unknown',

    'countries' => include 'hi.countries.php',
    'continents' => include 'hi.continents.php',
];
