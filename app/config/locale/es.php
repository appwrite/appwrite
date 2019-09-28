<?php

return [
    'settings.inspire' => '"El arte de ser sabio es el arte de saber qué pasar por alto"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'es',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s Equipo',
    'auth.emails.confirm.title' => 'Confirmación de la cuenta',
    'auth.emails.confirm.body' => 'es.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Reestablecer contraseña',
    'auth.emails.recovery.body' => 'es.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Invitación al Equipo %s en %s',
    'auth.emails.invitation.body' => 'es.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Desconocido',

    'countries' => include 'es.countries.php',
    'continents' => include 'es.continents.php',
];
