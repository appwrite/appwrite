<?php

/**
 * Init
 * 
 * Inializes both Appwrite API entry point, queue workers, and CLI tasks.
 * Set configuration, framework resources, app constants
 * 
 */
if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require_once __DIR__.'/../vendor/autoload.php';
}

use Utopia\App;
use Utopia\Request;
use Utopia\Response;
use Utopia\Config\Config;
use Utopia\Locale\Locale;
use Utopia\Registry\Registry;
use Appwrite\Auth\Auth;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use PHPMailer\PHPMailer\PHPMailer;

const APP_NAME = 'Appwrite';
const APP_DOMAIN = 'appwrite.io';
const APP_EMAIL_TEAM = 'team@localhost.test'; // Default email address
const APP_EMAIL_SECURITY = 'security@localhost.test'; // Default security email address
const APP_USERAGENT = APP_NAME.'-Server v%s. Please report abuse at %s';
const APP_MODE_ADMIN = 'admin';
const APP_PAGING_LIMIT = 15;
const APP_CACHE_BUSTER = 122;
const APP_VERSION_STABLE = '0.5.3';
const APP_STORAGE_UPLOADS = '/storage/uploads';
const APP_STORAGE_CACHE = '/storage/cache';
const APP_STORAGE_CERTIFICATES = '/storage/certificates';
const APP_STORAGE_CONFIG = '/storage/config';
const APP_SOCIAL_TWITTER = 'https://twitter.com/appwrite_io';
const APP_SOCIAL_TWITTER_HANDLE = 'appwrite_io';
const APP_SOCIAL_FACEBOOK = 'https://www.facebook.com/appwrite.io';
const APP_SOCIAL_LINKEDIN = 'https://www.linkedin.com/company/appwrite';
const APP_SOCIAL_INSTAGRAM = 'https://www.instagram.com/appwrite.io';
const APP_SOCIAL_GITHUB = 'https://github.com/appwrite';
const APP_SOCIAL_DISCORD = 'https://discord.gg/GSeTUeA';

$register = new Registry();
$request = new Request();
$response = new Response();

/*
 * ENV vars
 */
Config::load('providers', __DIR__.'/../app/config/providers.php');
Config::load('platforms', __DIR__.'/../app/config/platforms.php');
Config::load('locales', __DIR__.'/../app/config/locales.php');
Config::load('collections', __DIR__.'/../app/config/collections.php');

Config::setParam('env', $request->getServer('_APP_ENV', App::ENV_TYPE_PRODUCTION));
Config::setParam('domain', $request->getServer('HTTP_HOST', ''));
Config::setParam('domainVerification', false);
Config::setParam('version', $request->getServer('_APP_VERSION', 'UNKNOWN'));
Config::setParam('protocol', $request->getServer('HTTP_X_FORWARDED_PROTO', $request->getServer('REQUEST_SCHEME', 'https')));
Config::setParam('port', (string) parse_url(Config::getParam('protocol').'://'.$request->getServer('HTTP_HOST', ''), PHP_URL_PORT));

$utopia = new App('Asia/Tel_Aviv', Config::getParam('env'));

Resque::setBackend($request->getServer('_APP_REDIS_HOST', '')
    .':'.$request->getServer('_APP_REDIS_PORT', ''));

define('COOKIE_DOMAIN', 
    (
        $request->getServer('HTTP_HOST', null) === 'localhost' ||
        $request->getServer('HTTP_HOST', null) === 'localhost:'.Config::getParam('port') ||
        (filter_var($request->getServer('HTTP_HOST', null), FILTER_VALIDATE_IP) !== false)
    )
        ? null
        : '.'.parse_url(Config::getParam('protocol').'://'.$request->getServer('HTTP_HOST', ''), PHP_URL_HOST));
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
$register->set('cache', function () use ($request) { // Register cache connection
    $redis = new Redis();

    $redis->connect($request->getServer('_APP_REDIS_HOST', ''),
        $request->getServer('_APP_REDIS_PORT', ''));

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
Locale::setLanguage('km', include __DIR__.'/config/locales/km.php');
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

if (in_array($locale, Config::getParam('locales'))) {
    Locale::setDefault($locale);
}

stream_context_set_default([ // Set global user agent and http settings
    'http' => [
        'method' => 'GET',
        'user_agent' => sprintf(APP_USERAGENT,
            Config::getParam('version'),
            $request->getServer('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY)),
        'timeout' => 2,
    ],
]);

/*
 * Auth & Project Scope
 */
$consoleDB = new Database();
$consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
$consoleDB->setNamespace('app_console'); // Should be replaced with param if we want to have parent projects

$consoleDB->setMocks(Config::getParam('collections', []));
Authorization::disable();

$project = $consoleDB->getDocument($request->getParam('project', $request->getHeader('X-Appwrite-Project', null)));

Authorization::enable();

$console = $consoleDB->getDocument('console');

$mode = $request->getParam('mode', $request->getHeader('X-Appwrite-Mode', 'default'));

Auth::setCookieName('a_session_'.$project->getId());

if (APP_MODE_ADMIN === $mode) {
    Auth::setCookieName('a_session_'.$console->getId());
}

$session = Auth::decodeSession(
    $request->getCookie(Auth::$cookieName, // Get sessions
        $request->getCookie(Auth::$cookieName.'_legacy', // Get fallback session from old clients (no SameSite support)
                $request->getHeader('X-Appwrite-Key', '')))); // Get API Key

// Get fallback session from clients who block 3rd-party cookies
$response->addHeader('X-Debug-Fallback', 'false');

if(empty($session['id']) && empty($session['secret'])) {
    $response->addHeader('X-Debug-Fallback', 'true');
    $fallback = $request->getHeader('X-Fallback-Cookies', null);
    $fallback = json_decode($fallback, true);
    $session = Auth::decodeSession(((isset($fallback[Auth::$cookieName])) ? $fallback[Auth::$cookieName] : ''));
}

Auth::$unique = $session['id'];
Auth::$secret = $session['secret'];

$projectDB = new Database();
$projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
$projectDB->setNamespace('app_'.$project->getId());
$projectDB->setMocks(Config::getParam('collections', []));

$user = $projectDB->getDocument(Auth::$unique);

if (APP_MODE_ADMIN === $mode) {
    $user = $consoleDB->getDocument(Auth::$unique);

    $user
        ->setAttribute('$id', 'admin-'.$user->getAttribute('$id'))
    ;
}

if (empty($user->getId()) // Check a document has been found in the DB
    || Database::SYSTEM_COLLECTION_USERS !== $user->getCollection() // Validate returned document is really a user document
    || !Auth::tokenVerify($user->getAttribute('tokens', []), Auth::TOKEN_TYPE_LOGIN, Auth::$secret)) { // Validate user has valid login token
    $user = new Document(['$id' => '', '$collection' => Database::SYSTEM_COLLECTION_USERS]);
}

if (APP_MODE_ADMIN === $mode) {
    if (!empty($user->search('teamId', $project->getAttribute('teamId'), $user->getAttribute('memberships')))) {
        Authorization::disable();
    } else {
        $user = new Document(['$id' => '', '$collection' => Database::SYSTEM_COLLECTION_USERS]);
    }
}

// Set project mail
$register->get('smtp')
    ->setFrom(
        $request->getServer('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM),
        ($project->getId() === 'console')
            ? urldecode($request->getServer('_APP_SYSTEM_EMAIL_NAME', APP_NAME.' Server'))
            : sprintf(Locale::getText('account.emails.team'), $project->getAttribute('name')
        )
    );
