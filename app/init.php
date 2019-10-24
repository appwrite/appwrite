<?php

// Init
if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require_once __DIR__.'/../vendor/autoload.php';
}

use Utopia\App;
use Utopia\Request;
use Utopia\Response;
use Auth\Auth;
use Database\Database;
use Database\Document;
use Database\Validator\Authorization;
use Database\Adapter\MySQL as MySQLAdapter;
use Database\Adapter\Redis as RedisAdapter;
use Utopia\Locale\Locale;
use Utopia\Registry\Registry;
use PHPMailer\PHPMailer\PHPMailer;

const APP_NAME = 'Appwrite';
const APP_DOMAIN = 'appwrite.io';
const APP_EMAIL_TEAM = 'team@'.APP_DOMAIN;
const APP_EMAIL_SECURITY = 'security@'.APP_DOMAIN;
const APP_USERAGENT = APP_NAME.'-Server/%s Please report abuse at '.APP_EMAIL_SECURITY;
const APP_MODE_ADMIN = 'admin';
const APP_PAGING_LIMIT = 15;
const APP_VERSION_STABLE = '0.3.0';

$register = new Registry();
$request = new Request();
$response = new Response();

/*
 * ENV vars
 */
$env = $request->getServer('_APP_ENV', App::ENV_TYPE_PRODUCTION);
$domain = $request->getServer('HTTP_HOST', '');
$version = include __DIR__.'/../app/config/version.php';
$providers = include __DIR__.'/../app/config/providers.php'; // OAuth providers list
$locales = include __DIR__.'/../app/config/locales.php'; // OAuth providers list
$collections = include __DIR__.'/../app/config/collections.php'; // OAuth providers list
$redisHost = $request->getServer('_APP_REDIS_HOST', '');
$redisPort = $request->getServer('_APP_REDIS_PORT', '');
$utopia = new App('Asia/Tel_Aviv', $env);
$port = (string) (isset($_SERVER['HTTP_HOST'])) ? parse_url($_SERVER['HTTP_HOST'], PHP_URL_PORT) : '';

Resque::setBackend($redisHost.':'.$redisPort);

define('COOKIE_DOMAIN', ($request->getServer('HTTP_HOST', null) === 'localhost' || $request->getServer('HTTP_HOST', null) === 'localhost:'.$port) ? false : '.'.$request->getServer('HTTP_HOST', false));

/*
 * Registry
 */
$register->set('db', function () use ($request) { // Register DB connection
    $dbHost = $request->getServer('_APP_DB_HOST', '');
    $dbUser = $request->getServer('_APP_DB_USER', '');
    $dbPass = $request->getServer('_APP_DB_PASS', '');
    $dbScheme = $request->getServer('_APP_DB_SCHEMA', '');

    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, array(
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        PDO::ATTR_TIMEOUT => 5, // Seconds
    ));

    // Connection settings
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);   // Return arrays
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);        // Handle all errors with exceptions

    return $pdo;
});
$register->set('influxdb', function () use ($request) { // Register DB connection
    $host = $request->getServer('_APP_INFLUXDB_HOST', '');
    $port = $request->getServer('_APP_INFLUXDB_PORT', '');

    if (empty($host) || empty($port)) {
        return;
    }

    $client = new InfluxDB\Client($host, $port, '', '', false, false, 5);

    return $client;
});
$register->set('statsd', function () use ($request) { // Register DB connection
    $host = $request->getServer('_APP_STATSD_HOST', 'telegraf');
    $port = $request->getServer('_APP_STATSD_PORT', 8125);

    $connection = new \Domnikl\Statsd\Connection\UdpSocket($host, $port);
    $statsd = new \Domnikl\Statsd\Client($connection);

    return $statsd;
});
$register->set('cache', function () use ($redisHost, $redisPort) { // Register cache connection
    $redis = new Redis();

    $redis->connect($redisHost, $redisPort);

    return $redis;
});
$register->set('smtp', function () use ($request) {
    $mail = new PHPMailer(true);

    $mail->isSMTP();

    $username = $request->getServer('_APP_SMTP_USERNAME', '');
    $password = $request->getServer('_APP_SMTP_PASSWORD', '');

    $mail->XMailer = 'Appwrite Mailer';
    $mail->Host = $request->getServer('_APP_SMTP_HOST', 'smtp');
    $mail->Port = $request->getServer('_APP_SMTP_PORT', 25);
    $mail->SMTPAuth = (!empty($username) && !empty($password));
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = $request->getServer('_APP_SMTP_SECURE', '');

    $mail->setFrom('team@appwrite.io', APP_NAME.' Team');
    $mail->addReplyTo('team@appwrite.io', APP_NAME.' Team');

    $mail->isHTML(true);

    return $mail;
});

/*
 * Localization
 */
$locale = $request->getParam('locale', $request->getHeader('X-Appwrite-Locale', null));

Locale::$exceptions = false;

Locale::setLanguage('af', include __DIR__.'/config/locale/af.php');
Locale::setLanguage('ar', include __DIR__.'/config/locale/ar.php');
Locale::setLanguage('bn', include __DIR__.'/config/locale/bn.php');
Locale::setLanguage('cat', include __DIR__.'/config/locale/cat.php');
Locale::setLanguage('cz', include __DIR__.'/config/locale/cz.php');
Locale::setLanguage('de', include __DIR__.'/config/locale/de.php');
Locale::setLanguage('en', include __DIR__.'/config/locale/en.php');
Locale::setLanguage('es', include __DIR__.'/config/locale/es.php');
Locale::setLanguage('fi', include __DIR__.'/config/locale/fi.php');
Locale::setLanguage('fr', include __DIR__.'/config/locale/fr.php');
Locale::setLanguage('gr', include __DIR__.'/config/locale/gr.php');
Locale::setLanguage('he', include __DIR__.'/config/locale/he.php');
Locale::setLanguage('hi', include __DIR__.'/config/locale/hi.php');
Locale::setLanguage('hu', include __DIR__.'/config/locale/hu.php');
Locale::setLanguage('hy', include __DIR__.'/config/locale/hy.php');
Locale::setLanguage('id', include __DIR__.'/config/locale/id.php');
Locale::setLanguage('is', include __DIR__.'/config/locale/is.php');
Locale::setLanguage('it', include __DIR__.'/config/locale/it.php');
Locale::setLanguage('jv', include __DIR__.'/config/locale/ja.php');
Locale::setLanguage('jv', include __DIR__.'/config/locale/jv.php');
Locale::setLanguage('ko', include __DIR__.'/config/locale/ko.php');
Locale::setLanguage('lt', include __DIR__.'/config/locale/lt.php');
Locale::setLanguage('ml', include __DIR__.'/config/locale/ml.php');
Locale::setLanguage('ms', include __DIR__.'/config/locale/ms.php');
Locale::setLanguage('nl', include __DIR__.'/config/locale/nl.php');
Locale::setLanguage('no', include __DIR__.'/config/locale/no.php');
Locale::setLanguage('pl', include __DIR__.'/config/locale/pl.php');
Locale::setLanguage('pt-br', include __DIR__.'/config/locale/pt-br.php');
Locale::setLanguage('pt-pt', include __DIR__.'/config/locale/pt-pt.php');
Locale::setLanguage('ro', include __DIR__.'/config/locale/ro.php');
Locale::setLanguage('ru', include __DIR__ . '/config/locale/ru.php');
Locale::setLanguage('si', include __DIR__ . '/config/locale/si.php');
Locale::setLanguage('sl', include __DIR__ . '/config/locale/sl.php');
Locale::setLanguage('sq', include __DIR__ . '/config/locale/sq.php');
Locale::setLanguage('sv', include __DIR__ . '/config/locale/sv.php');
Locale::setLanguage('ta', include __DIR__ . '/config/locale/ta.php');
Locale::setLanguage('th', include __DIR__.'/config/locale/th.php');
Locale::setLanguage('tr', include __DIR__.'/config/locale/tr.php');
Locale::setLanguage('ua', include __DIR__.'/config/locale/ua.php');
Locale::setLanguage('vi', include __DIR__.'/config/locale/vi.php');
Locale::setLanguage('zh-cn', include __DIR__.'/config/locale/zh-cn.php');
Locale::setLanguage('zh-tw', include __DIR__.'/config/locale/zh-tw.php');

Locale::setDefault('en');

if (in_array($locale, $locales)) {
    Locale::setDefault($locale);
}

stream_context_set_default([ // Set global user agent and http settings
    'http' => [
        'method' => 'GET',
        'user_agent' => sprintf(APP_USERAGENT, $version),
        'timeout' => 2,
    ],
]);

/*
 * Auth & Project Scope
 */
$consoleDB = new Database();
$consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
$consoleDB->setNamespace('app_console'); // Should be replaced with param if we want to have parent projects
$consoleDB->setMocks($collections);

Authorization::disable();

$project = $consoleDB->getDocument($request->getParam('project', $request->getHeader('X-Appwrite-Project', null)));

Authorization::enable();

$console = $consoleDB->getDocument('console');

if (is_null($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS !== $project->getCollection()) {
    $project = $console;
}

$mode = $request->getParam('mode', $request->getHeader('X-Appwrite-Mode', 'default'));

Auth::setCookieName('a-session-'.$project->getUid());

if (APP_MODE_ADMIN === $mode) {
    Auth::setCookieName('a-session-'.$console->getUid());
}

$session = Auth::decodeSession($request->getCookie(Auth::$cookieName, $request->getHeader('X-Appwrite-Key', '')));
Auth::$unique = $session['id'];
Auth::$secret = $session['secret'];

$projectDB = new Database();
$projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
$projectDB->setNamespace('app_'.$project->getUid());
$projectDB->setMocks($collections);

$user = $projectDB->getDocument(Auth::$unique);

if (APP_MODE_ADMIN === $mode) {
    $user = $consoleDB->getDocument(Auth::$unique);

    $user
        ->setAttribute('$uid', 'admin-'.$user->getAttribute('$uid'))
    ;
}

if (empty($user->getUid()) // Check a document has been found in the DB
    || Database::SYSTEM_COLLECTION_USERS !== $user->getCollection() // Validate returned document is really a user document
    || !Auth::tokenVerify($user->getAttribute('tokens', []), Auth::TOKEN_TYPE_LOGIN, Auth::$secret)) { // Validate user has valid login token
    $user = new Document(['$uid' => '', '$collection' => Database::SYSTEM_COLLECTION_USERS]);
}

if (APP_MODE_ADMIN === $mode) {
    if (!empty($user->search('teamId', $project->getAttribute('teamId'), $user->getAttribute('memberships')))) {
        Authorization::disable();
    } else {
        $user = new Document(['$uid' => '', '$collection' => Database::SYSTEM_COLLECTION_USERS]);
    }
}

// Set project mail
$register->get('smtp')->setFrom(APP_EMAIL_TEAM, sprintf(Locale::getText('auth.emails.team'), $project->getAttribute('name')));
