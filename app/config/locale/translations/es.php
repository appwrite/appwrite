<?php

return [
    'settings.inspire' => '"El arte de ser sabio es el arte de saber qué pasar por alto"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'es',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => 'Equipo %s',
    'account.emails.verification.title' => 'Confirmación de la cuenta',
    'account.emails.verification.body' => 'es.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Restablecer contraseña',
    'account.emails.recovery.body' => 'es.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Invitación al Equipo %s en %s',
    'account.emails.invitation.body' => 'es.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Desconocido',

    'countries' => include 'es.countries.php',
    'continents' => include 'es.continents.php',
];
