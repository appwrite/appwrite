<?php

return [
    'settings.inspire' => '"A arte de ser sábio é a arte de saber o que deixar passar."', // This is the line printed in the homepage and console 'view-source'
    'settings.locale' => 'pt-br',
    'settings.direction' => 'ltr',

    // Service - Users
    'account.emails.team' => 'Time %s',
    'account.emails.verification.title' => 'Confirmação de Conta',
    'account.emails.verification.body' => 'pt-br.email.auth.confirm.tpl',
    'account.emails.recovery.title' => 'Redefinição de Senha',
    'account.emails.recovery.body' => 'pt-br.email.auth.recovery.tpl',
    'account.emails.invitation.title' => 'Convite para a Equipe %s em %s',
    'account.emails.invitation.body' => 'pt-br.email.auth.invitation.tpl',

    'locale.country.unknown' => 'Desconhecido',

    'countries' => include 'pt-br.countries.php',
    'continents' => include 'pt-br.continents.php',
];
