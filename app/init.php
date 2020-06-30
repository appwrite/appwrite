<?php

/**
 * Init
 * 
 * Inializes both Appwrite API entry point, queue workers, and CLI tasks.
 * Set configuration, framework resources, app constants
 * 
 */
if (\file_exists(__DIR__.'/../vendor/autoload.php')) {
    require_once __DIR__.'/../vendor/autoload.php';
}

use Utopia\App;
use Utopia\Config\Config;
use Utopia\Locale\Locale;
use Utopia\Registry\Registry;
use Appwrite\Database\Database;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Document;
use Appwrite\Event\Event;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\View;

const APP_NAME = 'Appwrite';
const APP_DOMAIN = 'appwrite.io';
const APP_EMAIL_TEAM = 'team@localhost.test'; // Default email address
const APP_EMAIL_SECURITY = 'security@localhost.test'; // Default security email address
const APP_USERAGENT = APP_NAME.'-Server v%s. Please report abuse at %s';
const APP_MODE_ADMIN = 'admin';
const APP_PAGING_LIMIT = 15;
const APP_CACHE_BUSTER = 125;
const APP_VERSION_STABLE = '0.6.2';
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
const APP_SOCIAL_DEV = 'https://dev.to/appwrite';

$register = new Registry();

App::setMode(App::getEnv('_APP_ENV', App::MODE_TYPE_PRODUCTION));

/*
 * ENV vars
 */
Config::load('events', __DIR__.'/../app/config/events.php');
Config::load('providers', __DIR__.'/../app/config/providers.php');
Config::load('platforms', __DIR__.'/../app/config/platforms.php');
Config::load('locales', __DIR__.'/../app/config/locales.php');
Config::load('collections', __DIR__.'/../app/config/collections.php');
Config::load('roles', __DIR__.'/../app/config/roles.php');  // User roles and scopes
Config::load('services', __DIR__.'/../app/config/services.php');  // List of services
Config::load('avatar-browsers', __DIR__.'/../app/config/avatars/browsers.php'); 
Config::load('avatar-credit-cards', __DIR__.'/../app/config/avatars/credit-cards.php'); 
Config::load('avatar-flags', __DIR__.'/../app/config/avatars/flags.php'); 
Config::load('storage-logos', __DIR__.'/../app/config/storage/logos.php'); 
Config::load('storage-mimes', __DIR__.'/../app/config/storage/mimes.php'); 
Config::load('storage-inputs', __DIR__.'/../app/config/storage/inputs.php'); 
Config::load('storage-outputs', __DIR__.'/../app/config/storage/outputs.php'); 

Resque::setBackend(App::getEnv('_APP_REDIS_HOST', '')
    .':'.App::getEnv('_APP_REDIS_PORT', ''));

/*
 * Registry
 */
$register->set('db', function () { // Register DB connection
    $dbHost = App::getEnv('_APP_DB_HOST', '');
    $dbUser = App::getEnv('_APP_DB_USER', '');
    $dbPass = App::getEnv('_APP_DB_PASS', '');
    $dbScheme = App::getEnv('_APP_DB_SCHEMA', '');

    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, array(
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        PDO::ATTR_TIMEOUT => 5, // Seconds
    ));

    // Connection settings
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);   // Return arrays
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);        // Handle all errors with exceptions

    return $pdo;
});
$register->set('influxdb', function () { // Register DB connection
    $host = App::getEnv('_APP_INFLUXDB_HOST', '');
    $port = App::getEnv('_APP_INFLUXDB_PORT', '');

    if (empty($host) || empty($port)) {
        return;
    }

    $client = new InfluxDB\Client($host, $port, '', '', false, false, 5);

    return $client;
});
$register->set('statsd', function () { // Register DB connection
    $host = App::getEnv('_APP_STATSD_HOST', 'telegraf');
    $port = App::getEnv('_APP_STATSD_PORT', 8125);

    $connection = new \Domnikl\Statsd\Connection\UdpSocket($host, $port);
    $statsd = new \Domnikl\Statsd\Client($connection);

    return $statsd;
});
$register->set('cache', function () { // Register cache connection
    $redis = new Redis();

    $redis->connect(App::getEnv('_APP_REDIS_HOST', ''),
        App::getEnv('_APP_REDIS_PORT', ''));

    return $redis;
});
$register->set('smtp', function () {
    $mail = new PHPMailer(true);

    $mail->isSMTP();

    $username = App::getEnv('_APP_SMTP_USERNAME', null);
    $password = App::getEnv('_APP_SMTP_PASSWORD', null);

    $mail->XMailer = 'Appwrite Mailer';
    $mail->Host = App::getEnv('_APP_SMTP_HOST', 'smtp');
    $mail->Port = App::getEnv('_APP_SMTP_PORT', 25);
    $mail->SMTPAuth = (!empty($username) && !empty($password));
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = App::getEnv('_APP_SMTP_SECURE', false);
    $mail->SMTPAutoTLS = false;
    $mail->CharSet = 'UTF-8';

    $from = \urldecode(App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME.' Server'));
    $email = App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);

    $mail->setFrom($email, $from);
    $mail->addReplyTo($email, $from);

    $mail->isHTML(true);

    return $mail;
});
$register->set('queue-webhook', function () {
    return new Event('v1-webhooks', 'WebhooksV1');
});
$register->set('queue-audit', function () {
    return new Event('v1-audits', 'AuditsV1');
});
$register->set('queue-usage', function () {
    return new Event('v1-usage', 'UsageV1');
});
$register->set('queue-mails', function () {
    return new Event('v1-mails', 'MailsV1');
});
$register->set('queue-deletes', function () {
    return new Event('v1-deletes', 'DeletesV1');
});

/*
 * Localization
 */
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

\stream_context_set_default([ // Set global user agent and http settings
    'http' => [
        'method' => 'GET',
        'user_agent' => \sprintf(APP_USERAGENT,
            Config::getParam('version'),
            App::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY)),
        'timeout' => 2,
    ],
]);


// Runtime Execution

App::setResource('register', function() use ($register) {
    return $register;
});

App::setResource('layout', function($locale) {
    $layout = new View(__DIR__.'/views/layouts/default.phtml');
    $layout->setParam('locale', $locale);

    return $layout;
}, ['locale']);

App::setResource('locale', function() {
    return new Locale('en');
});

// Queues
App::setResource('webhook', function($register) {
    return $register->get('queue-webhook');
}, ['register']);

App::setResource('audit', function($register) {
    return $register->get('queue-audit');
}, ['register']);

App::setResource('usage', function($register) {
    return $register->get('queue-usage');
}, ['register']);

App::setResource('mail', function($register) {
    return $register->get('queue-mails');
}, ['register']);

App::setResource('deletes', function($register) {
    return $register->get('queue-deletes');
}, ['register']);

// Test Mock
App::setResource('clients', function() { return []; });

App::setResource('user', function() { return new Document([]); });

App::setResource('project', function() { return new Document([]); });

App::setResource('console', function() { return new Document([]); });

App::setResource('consoleDB', function($register) {
    $consoleDB = new Database();
    $consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
    $consoleDB->setNamespace('app_console'); // Should be replaced with param if we want to have parent projects
    
    $consoleDB->setMocks(Config::getParam('collections', []));
}, ['register']);

App::setResource('projectDB', function() { return new Database([]); });

App::setResource('mode', function() { return false; });
