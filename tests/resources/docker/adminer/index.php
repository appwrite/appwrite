<?php

function onPostgresHost(): bool
{
    return explode(':', $_SERVER['HTTP_HOST'] ?? '')[0] === 'postgres.localhost';
}

function defaultAuth(): array
{
    return [
        'server' => $_ENV['ADMINER_DEFAULT_SERVER'],
        'driver' => (onPostgresHost() || $_ENV['ADMINER_DEFAULT_DRIVER'] === 'postgresql') ? 'pgsql' : 'server',
        'username' => $_ENV['ADMINER_DEFAULT_USERNAME'],
        'password' => $_ENV['ADMINER_DEFAULT_PASSWORD'],
        'db' => $_ENV['ADMINER_DEFAULT_DB'],
    ];
}

// Adminer opens the public schema unless ?ns= names another one.
if (onPostgresHost() && isset($_GET['username']) && !isset($_GET['ns'])) {
    $_GET['ns'] = $_ENV['ADMINER_DEFAULT_DB'] ?: 'appwrite';
    header('Location: ?' . http_build_query($_GET));
    exit;
}

// Adminer 6 ignores server-side POST autologin.
function adminer_object()
{
    return new class () extends Adminer\Adminer {
        public function loginForm()
        {
            parent::loginForm();
            // A login is already in flight or just failed; resubmitting would loop forever.
            if (isset($_GET['username']) || !empty($_POST['auth'])) {
                return;
            }
            $auth = json_encode(defaultAuth(), JSON_THROW_ON_ERROR);
            echo Adminer\script(
                'document.addEventListener("DOMContentLoaded",()=>{'
                . 'const a=' . $auth . ';'
                . 'const f=document.querySelector("#content form");'
                . 'if(!f||!f["auth[driver]"])return;'
                . 'f["auth[driver]"].value=a.driver;'
                . 'f["auth[server]"].value=a.server;'
                . 'f["auth[username]"].value=a.username;'
                . 'f["auth[password]"].value=a.password;'
                . 'f["auth[db]"].value=a.db;'
                . 'f.submit();});'
            );
        }
    };
}

include './adminer.php';
