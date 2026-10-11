<?php

// ruleid: php.appwrite.raw-sql-interpolation
$pdo->query('SELECT * FROM users WHERE email = ' . $email);

// ruleid: php.appwrite.raw-sql-interpolation
$pdo->query("SELECT * FROM {$table} WHERE id = 1");

// ruleid: php.appwrite.raw-sql-interpolation
$pdo->exec("DELETE FROM sessions WHERE userId = '$userId'");

// ruleid: php.appwrite.raw-sql-interpolation
$statement = $pdo->prepare(\sprintf('UPDATE %s SET name = ? WHERE id = ?', $table));

// ok: php.appwrite.raw-sql-interpolation
$statement = $pdo->prepare('SELECT * FROM users WHERE email = :email');

// ok: php.appwrite.raw-sql-interpolation
$pdo->query('SELECT 1');

// ok: php.appwrite.raw-sql-interpolation
$client->query('/v1/health/' . $service);

// ok: php.appwrite.raw-sql-interpolation
$documents = $dbForProject->find('users', [Query::equal('email', [$email])]);
