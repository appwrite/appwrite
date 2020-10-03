<?php

return [
    'settings.inspire' => '"వివేకం యొక్క కళ ఏమిటంటే నిర్లక్ష్యం చేయవలసినది తెలుసుకోవడం."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'tel',
    'settings.direction' => 'rtl',

    // Service - Users
    'account.emails.team' => 'జట్టు %s',
    'account.emails.verification.title' => 'ఖాతా నిర్ధారణ',
    'account.emails.verification.body' => 'tel.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'పాస్వర్డ్ రీసెట్',
    'account.emails.recovery.body' => 'tel.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'జట్టు కోసం కాల్ చేయండి %s a లో %s',
    'account.emails.invitation.body' => 'tel.email.auth.invitation.tpl',

    'locale.country.unknown' => 'తెలియదు',

    'countries' => include 'tel.countries.php',
    'continents' => include 'tel.continents.php',
];
