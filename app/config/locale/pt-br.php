<?php

return [
    'settings.inspire' => '"The art of being wise is the art of knowing what to overlook."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'pt-br',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => 'Time %s',
    'auth.emails.confirm.title' => 'Confirmação de Conta',
    'auth.emails.confirm.body' => 'pt-br.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Redefinição de Senha',
    'auth.emails.recovery.body' => 'pt-br.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Convite para a Equipe %s em %s',
    'auth.emails.invitation.body' => 'pt-br.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Desconhecido',

    'countries' => include 'pt-br.countries.php',
    'continents' => include 'pt-br.continents.php',
];
