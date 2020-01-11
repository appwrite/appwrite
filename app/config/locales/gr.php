<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'gr',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => 'Ομάδα %s',
    'auth.emails.confirm.title' => 'Επιβεβαίωση Λογαριασμού',
    'auth.emails.confirm.body' => 'gr.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Επαναφορά Κωδικού Πρόσβασης',
    'auth.emails.recovery.body' => 'gr.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Πρόσκληση στην ομάδα %s στο %s',
    'auth.emails.invitation.body' => 'gr.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Άγνωστο',

    'countries' => include 'gr.countries.php',
    'continents' => include 'gr.continents.php',
];
