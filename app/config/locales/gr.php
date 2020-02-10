<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'gr',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => 'Ομάδα %s',
    'account.emails.verification.title' => 'Επιβεβαίωση Λογαριασμού',
    'account.emails.verification.body' => 'gr.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Επαναφορά Κωδικού Πρόσβασης',
    'account.emails.recovery.body' => 'gr.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Πρόσκληση στην ομάδα %s στο %s',
    'account.emails.invitation.body' => 'gr.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Άγνωστο',

    'countries' => include 'gr.countries.php',
    'continents' => include 'gr.continents.php',
];
