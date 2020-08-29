<?php

return [
    'settings.inspire' => '"L\'art de ser savi és l\'art de saber què passar per alt"', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'cat',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => '%s Equip',
    'account.emails.verification.title' => 'Confirmació del compte',
    'account.emails.verification.body' => 'cat.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Restablir contrasenya',
    'account.emails.recovery.body' => 'cat.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Invitació a l\'Equip %s a %s',
    'account.emails.invitation.body' => 'cat.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Desconegut',

    'countries' => include 'cat.countries.php',
    'continents' => include 'cat.continents.php',
];
