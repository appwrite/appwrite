<?php

return [
    'settings.inspire' => '"L\'art d\'être sage est l\'art de savoir quoi négliger."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'fr',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => 'Équipe %s',
    'account.emails.verification.title' => 'Confirmation de création de compte',
    'account.emails.verification.body' => 'fr.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Réinitialisation de mot de passe',
    'account.emails.recovery.body' => 'fr.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Invitation pour l\'équipe %s au projet %s',
    'account.emails.invitation.body' => 'fr.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Inconnu',

    'countries' => include 'fr.countries.php',
    'continents' => include 'fr.continents.php',
];
