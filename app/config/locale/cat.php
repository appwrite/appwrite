<?php

return [
    'settings.inspire' => '"L\'art de ser sabi és l\'art de saber què passar per alt"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'cat',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => '%s Equip',
    'auth.emails.confirm.title' => 'Confirmació del compte',
    'auth.emails.confirm.body' => 'cat.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Restablir contrasenya',
    'auth.emails.recovery.body' => 'cat.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Invitació a l\'Equipo %s a %s',
    'auth.emails.invitation.body' => 'cat.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Desconegut',

    'countries' => include 'cat.countries.php',
    'continents' => include 'cat.continents.php',
];
