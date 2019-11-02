<?php

return [
    'settings.inspire' => '"A arte de ser sábio é a arte de saber o que ultrapassar."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'pt-pt',
    'settings.direction' => 'ltr',

    // Service - Users
    'auth.emails.team' => 'Equipa %s',
    'auth.emails.confirm.title' => 'Confirmação de Conta',
    'auth.emails.confirm.body' => 'pt-pt.email.auth.confirm.tpl',
    'auth.emails.recovery.title' => 'Repor palavra-passe',
    'auth.emails.recovery.body' => 'pt-pt.email.auth.recovery.tpl',
    'auth.emails.invitation.title' => 'Convite para a Equipa %s em %s',
    'auth.emails.invitation.body' => 'pt-pt.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Desconhecido',

    'countries' => include 'pt-pt.countries.php',
    'continents' => include 'pt-pt.continents.php',
];
