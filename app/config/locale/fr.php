<?php

return [
    'settings.inspire' => '"L\'art d\'être sage est l\'art de savoir quoi négliger."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'fr',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => 'Équipe %s',
    'auth.emails.confirm.title' => 'Confirmation de création de compte',
    'auth.emails.confirm.body' => 'fr.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Réinitialisation de mot de passe',
    'auth.emails.recovery.body' => 'fr.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Invitation pour l\'équipe %s au projet %s',
    'auth.emails.invitation.body' => 'fr.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Inconnu',

    'countries' => include 'fr.countries.php',
    'continents' => include 'fr.continents.php',
];
