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
const APP_VERSION_STABLE = '0.5.0';

$register = new Registry();
$request = new Request();
$response = new Response();

/*
 * ENV vars
 */
$env = $request->getServer('_APP_ENV', App::ENV_TYPE_PRODUCTION);
$domain = $request->getServer('HTTP_HOST', '');
$version = $request->getServer('_APP_VERSION', 'UNKNOWN');
$providers = include __DIR__.'/../app/config/providers.php'; // OAuth providers list
$platforms = include __DIR__.'/../app/config/platforms.php';
$locales = include __DIR__.'/../app/config/locales.php'; // Locales list
$collections = include __DIR__.'/../app/config/collections.php'; // Collections list
$redisHost = $request->getServer('_APP_REDIS_HOST', '');
$redisPort = $request->getServer('_APP_REDIS_PORT', '');
$utopia = new App('Asia/Tel_Aviv', $env);
$scheme = $request->getServer('REQUEST_SCHEME', '');
$port = (string) parse_url($scheme.'://'.$request->getServer('HTTP_HOST', ''), PHP_URL_PORT);

Resque::setBackend($redisHost.':'.$redisPort);

define('COOKIE_DOMAIN', 
    (
        $request->getServer('HTTP_HOST', null) === 'localhost' ||
        $request->getServer('HTTP_HOST', null) === 'localhost:'.$port ||
        (filter_var($request->getServer('HTTP_HOST', null), FILTER_VALIDATE_IP) !== false)
    )
        ? null
        : '.'.parse_url($scheme.'://'.$request->getServer('HTTP_HOST', ''), PHP_URL_HOST));
define('COOKIE_SAMESITE', Response::COOKIE_SAMESITE_NONE);

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

    $username = $request->getServer('_APP_SMTP_USERNAME', null);
    $password = $request->getServer('_APP_SMTP_PASSWORD', null);

    $mail->XMailer = 'Appwrite Mailer';
    $mail->Host = $request->getServer('_APP_SMTP_HOST', 'smtp');
    $mail->Port = $request->getServer('_APP_SMTP_PORT', 25);
    $mail->SMTPAuth = (!empty($username) && !empty($password));
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = $request->getServer('_APP_SMTP_SECURE', false);
    $mail->SMTPAutoTLS = false;

    $from = urldecode($request->getServer('_APP_SYSTEM_EMAIL_NAME', APP_NAME.' Server'));
    $email = $request->getServer('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);

    $mail->setFrom($email, $from);
    $mail->addReplyTo($email, $from);

    $mail->isHTML(true);

    return $mail;
});

/*
 * Localization
 */
$locale = $request->getParam('locale', $request->getHeader('X-Appwrite-Locale', null));

Locale::$exceptions = false;

Locale::setLanguage('af', include __DIR__.'/config/locales/af.php');
Locale::setLanguage('ar', include __DIR__.'/config/locales/ar.php');
Locale::setLanguage('bn', include __DIR__.'/config/locales/bn.php');
Locale::setLanguage('cat', include __DIR__.'/config/locales/cat.php');
Locale::setLanguage('cz', include __DIR__.'/config/locales/cz.php');
Locale::setLanguage('de', include __DIR__.'/config/locales/de.php');
Locale::setLanguage('en', include __DIR__.'/config/locales/en.php');
Locale::setLanguage('es', include __DIR__.'/config/locales/es.php');
Locale::setLanguage('fi', include __DIR__.'/config/locales/fi.php');
Locale::setLanguage('fo', include __DIR__.'/config/locales/fo.php');
Locale::setLanguage('fr', include __DIR__.'/config/locales/fr.php');
Locale::setLanguage('gr', include __DIR__.'/config/locales/gr.php');
Locale::setLanguage('he', include __DIR__.'/config/locales/he.php');
Locale::setLanguage('hi', include __DIR__.'/config/locales/hi.php');
Locale::setLanguage('hu', include __DIR__.'/config/locales/hu.php');
Locale::setLanguage('hy', include __DIR__.'/config/locales/hy.php');
Locale::setLanguage('id', include __DIR__.'/config/locales/id.php');
Locale::setLanguage('is', include __DIR__.'/config/locales/is.php');
Locale::setLanguage('it', include __DIR__.'/config/locales/it.php');
Locale::setLanguage('ja', include __DIR__.'/config/locales/ja.php');
Locale::setLanguage('jv', include __DIR__.'/config/locales/jv.php');
Locale::setLanguage('ko', include __DIR__.'/config/locales/ko.php');
Locale::setLanguage('lt', include __DIR__.'/config/locales/lt.php');
Locale::setLanguage('ml', include __DIR__.'/config/locales/ml.php');
Locale::setLanguage('ms', include __DIR__.'/config/locales/ms.php');
Locale::setLanguage('nl', include __DIR__.'/config/locales/nl.php');
Locale::setLanguage('no', include __DIR__.'/config/locales/no.php');
Locale::setLanguage('ph', include __DIR__.'/config/locales/ph.php');
Locale::setLanguage('pl', include __DIR__.'/config/locales/pl.php');
Locale::setLanguage('pn', include __DIR__.'/config/locales/pn.php');
Locale::setLanguage('pt-br', include __DIR__.'/config/locales/pt-br.php');
Locale::setLanguage('pt-pt', include __DIR__.'/config/locales/pt-pt.php');
Locale::setLanguage('ro', include __DIR__.'/config/locales/ro.php');
Locale::setLanguage('ru', include __DIR__ . '/config/locales/ru.php');
Locale::setLanguage('si', include __DIR__ . '/config/locales/si.php');
Locale::setLanguage('sl', include __DIR__ . '/config/locales/sl.php');
Locale::setLanguage('sq', include __DIR__ . '/config/locales/sq.php');
Locale::setLanguage('sv', include __DIR__ . '/config/locales/sv.php');
Locale::setLanguage('ta', include __DIR__ . '/config/locales/ta.php');
Locale::setLanguage('th', include __DIR__.'/config/locales/th.php');
Locale::setLanguage('tr', include __DIR__.'/config/locales/tr.php');
Locale::setLanguage('ua', include __DIR__.'/config/locales/ua.php');
Locale::setLanguage('vi', include __DIR__.'/config/locales/vi.php');
Locale::setLanguage('zh-cn', include __DIR__.'/config/locales/zh-cn.php');
Locale::setLanguage('zh-tw', include __DIR__.'/config/locales/zh-tw.php');

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

$mode = $request->getParam('mode', $request->getHeader('X-Appwrite-Mode', 'default'));

Auth::setCookieName('a_session_'.$project->getUid());

if (APP_MODE_ADMIN === $mode) {
    Auth::setCookieName('a_session_'.$console->getUid());
}

$session = Auth::decodeSession(
    $request->getCookie(Auth::$cookieName, // Get sessions
        $request->getCookie(Auth::$cookieName.'_legacy', // Get fallback session from old clients (no SameSite support)
            $request->getHeader('X-Appwrite-Key', '')))); // Get API Key
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
$register->get('smtp')
    ->setFrom(
        $request->getServer('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM),
        ($project->getUid() === 'console')
            ? urldecode($request->getServer('_APP_SYSTEM_EMAIL_NAME', APP_NAME.' Server'))
            : sprintf(Locale::getText('account.emails.team'), $project->getAttribute('name')
        )
    );
