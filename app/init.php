<?php

/**
 * Init
 * 
 * Initializes both Appwrite API entry point, queue workers, and CLI tasks.
 * Set configuration, framework resources & app constants
 * 
 */
if (\file_exists(__DIR__.'/../vendor/autoload.php')) {
    require_once __DIR__.'/../vendor/autoload.php';
}

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Auth\Auth;
use Appwrite\Database\Database;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Event\Event;
use Appwrite\Extend\PDO;
use Appwrite\OpenSSL\OpenSSL;
use Utopia\App;
use Utopia\View;
use Utopia\Config\Config;
use Utopia\Locale\Locale;
use Utopia\Registry\Registry;
use MaxMind\Db\Reader;
use PHPMailer\PHPMailer\PHPMailer;
use PDO as PDONative;

const APP_NAME = 'Appwrite';
const APP_DOMAIN = 'appwrite.io';
const APP_EMAIL_TEAM = 'team@localhost.test'; // Default email address
const APP_EMAIL_SECURITY = ''; // Default security email address
const APP_USERAGENT = APP_NAME.'-Server v%s. Please report abuse at %s';
const APP_MODE_DEFAULT = 'default';
const APP_MODE_ADMIN = 'admin';
const APP_PAGING_LIMIT = 12;
const APP_CACHE_BUSTER = 145;
const APP_VERSION_STABLE = '0.8.0';
const APP_STORAGE_UPLOADS = '/storage/uploads';
const APP_STORAGE_FUNCTIONS = '/storage/functions';
const APP_STORAGE_CACHE = '/storage/cache';
const APP_STORAGE_CERTIFICATES = '/storage/certificates';
const APP_STORAGE_CONFIG = '/storage/config';
const APP_SOCIAL_TWITTER = 'https://twitter.com/appwrite_io';
const APP_SOCIAL_TWITTER_HANDLE = 'appwrite_io';
const APP_SOCIAL_FACEBOOK = 'https://www.facebook.com/appwrite.io';
const APP_SOCIAL_LINKEDIN = 'https://www.linkedin.com/company/appwrite';
const APP_SOCIAL_INSTAGRAM = 'https://www.instagram.com/appwrite.io';
const APP_SOCIAL_GITHUB = 'https://github.com/appwrite';
const APP_SOCIAL_DISCORD = 'https://appwrite.io/discord';
const APP_SOCIAL_DEV = 'https://dev.to/appwrite';
const APP_SOCIAL_STACKSHARE = 'https://stackshare.io/appwrite'; 
// Deletion Types
const DELETE_TYPE_DOCUMENT = 'document';
const DELETE_TYPE_EXECUTIONS = 'executions';
const DELETE_TYPE_AUDIT = 'audit';
const DELETE_TYPE_ABUSE = 'abuse';
const DELETE_TYPE_CERTIFICATES = 'certificates';

$register = new Registry();

App::setMode(App::getEnv('_APP_ENV', App::MODE_TYPE_PRODUCTION));

/*
 * ENV vars
 */
Config::load('events', __DIR__.'/config/events.php');
Config::load('providers', __DIR__.'/config/providers.php');
Config::load('platforms', __DIR__.'/config/platforms.php');
Config::load('collections', __DIR__.'/config/collections.php');
Config::load('environments', __DIR__.'/config/environments.php');
Config::load('roles', __DIR__.'/config/roles.php');  // User roles and scopes
Config::load('scopes', __DIR__.'/config/scopes.php');  // User roles and scopes
Config::load('services', __DIR__.'/config/services.php');  // List of services
Config::load('variables', __DIR__.'/config/variables.php');  // List of env variables
Config::load('avatar-browsers', __DIR__.'/config/avatars/browsers.php'); 
Config::load('avatar-credit-cards', __DIR__.'/config/avatars/credit-cards.php'); 
Config::load('avatar-flags', __DIR__.'/config/avatars/flags.php'); 
Config::load('locale-codes', __DIR__.'/config/locale/codes.php'); 
Config::load('locale-currencies', __DIR__.'/config/locale/currencies.php'); 
Config::load('locale-eu', __DIR__.'/config/locale/eu.php'); 
Config::load('locale-languages', __DIR__.'/config/locale/languages.php'); 
Config::load('locale-phones', __DIR__.'/config/locale/phones.php'); 
Config::load('storage-logos', __DIR__.'/config/storage/logos.php'); 
Config::load('storage-mimes', __DIR__.'/config/storage/mimes.php'); 
Config::load('storage-inputs', __DIR__.'/config/storage/inputs.php'); 
Config::load('storage-outputs', __DIR__.'/config/storage/outputs.php'); 

$user = App::getEnv('_APP_REDIS_USER','');
$pass = App::getEnv('_APP_REDIS_PASS','');
if(!empty($user) || !empty($pass)) {
    Resque::setBackend('redis://'.$user.':'.$pass.'@'.App::getEnv('_APP_REDIS_HOST', '').':'.App::getEnv('_APP_REDIS_PORT', ''));
} else {
    Resque::setBackend(App::getEnv('_APP_REDIS_HOST', '').':'.App::getEnv('_APP_REDIS_PORT', ''));
}
/**
 * DB Filters
 */
Database::addFilter('json',
    function($value) {
        if(!is_array($value)) {
            return $value;
        }
        return json_encode($value);
    },
    function($value) {
        return json_decode($value, true);
    }
);

Database::addFilter('encrypt',
    function($value) {
        $key = App::getEnv('_APP_OPENSSL_KEY_V1');
        $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
        $tag = null;
        
        return json_encode([
            'data' => OpenSSL::encrypt($value, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag),
            'method' => OpenSSL::CIPHER_AES_128_GCM,
            'iv' => bin2hex($iv),
            'tag' => bin2hex($tag),
            'version' => '1',
        ]);
    },
    function($value) {
        $value = json_decode($value, true);
        $key = App::getEnv('_APP_OPENSSL_KEY_V'.$value['version']);

        return OpenSSL::decrypt($value['data'], $value['method'], $key, 0, hex2bin($value['iv']), hex2bin($value['tag']));
    }
);

/*
 * Registry
 */
$register->set('db', function () { // Register DB connection
    $dbHost = App::getEnv('_APP_DB_HOST', '');
    $dbUser = App::getEnv('_APP_DB_USER', '');
    $dbPass = App::getEnv('_APP_DB_PASS', '');
    $dbScheme = App::getEnv('_APP_DB_SCHEMA', '');

    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, array(
        PDONative::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        PDONative::ATTR_TIMEOUT => 3, // Seconds
        PDONative::ATTR_PERSISTENT => true
    ));

    // Connection settings
    $pdo->setAttribute(PDONative::ATTR_DEFAULT_FETCH_MODE, PDONative::FETCH_ASSOC);   // Return arrays
    $pdo->setAttribute(PDONative::ATTR_ERRMODE, PDONative::ERRMODE_EXCEPTION);        // Handle all errors with exceptions

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
    $redis->pconnect(App::getEnv('_APP_REDIS_HOST', ''), App::getEnv('_APP_REDIS_PORT', ''));
    $user = App::getEnv('_APP_REDIS_USER','');
    $pass = App::getEnv('_APP_REDIS_PASS','');
    $auth = [];
    if(!empty($user)) {
        $auth["user"] = $user;
    }
    if(!empty($pass)) {
        $auth["pass"] = $pass;
    }
    if(!empty($auth)) {
        $redis->auth($auth);
    }
    $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

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
$register->set('geodb', function () {
    return new Reader(__DIR__.'/db/DBIP/dbip-country-lite-2021-02.mmdb');
});

/*
 * Localization
 */
Locale::$exceptions = false;
Locale::setLanguage('af', include __DIR__.'/config/locale/translations/af.php');
Locale::setLanguage('ar', include __DIR__.'/config/locale/translations/ar.php');
Locale::setLanguage('ba', include __DIR__.'/config/locale/translations/ba.php');
Locale::setLanguage('be', include __DIR__.'/config/locale/translations/be.php');
Locale::setLanguage('bg', include __DIR__.'/config/locale/translations/bg.php');
Locale::setLanguage('bn', include __DIR__.'/config/locale/translations/bn.php');
Locale::setLanguage('cat', include __DIR__.'/config/locale/translations/cat.php');
Locale::setLanguage('cz', include __DIR__.'/config/locale/translations/cz.php');
Locale::setLanguage('de', include __DIR__.'/config/locale/translations/de.php');
Locale::setLanguage('en', include __DIR__.'/config/locale/translations/en.php');
Locale::setLanguage('es', include __DIR__.'/config/locale/translations/es.php');
Locale::setLanguage('fa', include __DIR__.'/config/locale/translations/fa.php');
Locale::setLanguage('fi', include __DIR__.'/config/locale/translations/fi.php');
Locale::setLanguage('fo', include __DIR__.'/config/locale/translations/fo.php');
Locale::setLanguage('fr', include __DIR__.'/config/locale/translations/fr.php');
Locale::setLanguage('gr', include __DIR__.'/config/locale/translations/gr.php');
Locale::setLanguage('gu', include __DIR__.'/config/locale/translations/gu.php');
Locale::setLanguage('he', include __DIR__.'/config/locale/translations/he.php');
Locale::setLanguage('hi', include __DIR__.'/config/locale/translations/hi.php');
Locale::setLanguage('hu', include __DIR__.'/config/locale/translations/hu.php');
Locale::setLanguage('hy', include __DIR__.'/config/locale/translations/hy.php');
Locale::setLanguage('id', include __DIR__.'/config/locale/translations/id.php');
Locale::setLanguage('is', include __DIR__.'/config/locale/translations/is.php');
Locale::setLanguage('it', include __DIR__.'/config/locale/translations/it.php');
Locale::setLanguage('ja', include __DIR__.'/config/locale/translations/ja.php');
Locale::setLanguage('jv', include __DIR__.'/config/locale/translations/jv.php');
Locale::setLanguage('ka', include __DIR__.'/config/locale/translations/ka.php');
Locale::setLanguage('km', include __DIR__.'/config/locale/translations/km.php');
Locale::setLanguage('ko', include __DIR__.'/config/locale/translations/ko.php');
Locale::setLanguage('lt', include __DIR__.'/config/locale/translations/lt.php');
Locale::setLanguage('ml', include __DIR__.'/config/locale/translations/ml.php');
Locale::setLanguage('mr', include __DIR__.'/config/locale/translations/mr.php');
Locale::setLanguage('ms', include __DIR__.'/config/locale/translations/ms.php');
Locale::setLanguage('nl', include __DIR__.'/config/locale/translations/nl.php');
Locale::setLanguage('no', include __DIR__.'/config/locale/translations/no.php');
Locale::setLanguage('np', include __DIR__.'/config/locale/translations/np.php');
Locale::setLanguage('od', include __DIR__.'/config/locale/translations/od.php');
Locale::setLanguage('ph', include __DIR__.'/config/locale/translations/ph.php');
Locale::setLanguage('pl', include __DIR__.'/config/locale/translations/pl.php');
Locale::setLanguage('pt-br', include __DIR__.'/config/locale/translations/pt-br.php');
Locale::setLanguage('pt-pt', include __DIR__.'/config/locale/translations/pt-pt.php');
Locale::setLanguage('pa', include __DIR__.'/config/locale/translations/pa.php');
Locale::setLanguage('ro', include __DIR__.'/config/locale/translations/ro.php');
Locale::setLanguage('ru', include __DIR__ . '/config/locale/translations/ru.php');
Locale::setLanguage('si', include __DIR__ . '/config/locale/translations/si.php');
Locale::setLanguage('sl', include __DIR__ . '/config/locale/translations/sl.php');
Locale::setLanguage('sq', include __DIR__ . '/config/locale/translations/sq.php');
Locale::setLanguage('sv', include __DIR__ . '/config/locale/translations/sv.php');
Locale::setLanguage('ta', include __DIR__ . '/config/locale/translations/ta.php');
Locale::setLanguage('th', include __DIR__.'/config/locale/translations/th.php');
Locale::setLanguage('tr', include __DIR__.'/config/locale/translations/tr.php');
Locale::setLanguage('ua', include __DIR__.'/config/locale/translations/ua.php');
Locale::setLanguage('ur', include __DIR__.'/config/locale/translations/ur.php');
Locale::setLanguage('vi', include __DIR__.'/config/locale/translations/vi.php');
Locale::setLanguage('zh-cn', include __DIR__.'/config/locale/translations/zh-cn.php');
Locale::setLanguage('zh-tw', include __DIR__.'/config/locale/translations/zh-tw.php');

\stream_context_set_default([ // Set global user agent and http settings
    'http' => [
        'method' => 'GET',
        'user_agent' => \sprintf(APP_USERAGENT,
            App::getEnv('_APP_VERSION', 'UNKNOWN'),
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
App::setResource('events', function($register) {
    return new Event('', '');
}, ['register']);

App::setResource('audits', function($register) {
    return new Event(Event::AUDITS_QUEUE_NAME, Event::AUDITS_CLASS_NAME);
}, ['register']);

App::setResource('usage', function($register) {
    return new Event(Event::USAGE_QUEUE_NAME, Event::USAGE_CLASS_NAME);
}, ['register']);

App::setResource('mails', function($register) {
    return new Event(Event::MAILS_QUEUE_NAME, Event::MAILS_CLASS_NAME);
}, ['register']);

App::setResource('deletes', function($register) {
    return new Event(Event::DELETE_QUEUE_NAME, Event::DELETE_CLASS_NAME);
}, ['register']);

// Test Mock
App::setResource('clients', function($request, $console, $project) {
    $console->setAttribute('platforms', [ // Allways allow current host
        '$collection' => Database::SYSTEM_COLLECTION_PLATFORMS,
        'name' => 'Current Host',
        'type' => 'web',
        'hostname' => $request->getHostname(),
    ], Document::SET_TYPE_APPEND);
    
    /**
     * Get All verified client URLs for both console and current projects
     * + Filter for duplicated entries
     */
    $clientsConsole = \array_map(function ($node) {
        return $node['hostname'];
    }, \array_filter($console->getAttribute('platforms', []), function ($node) {
        if (isset($node['type']) && $node['type'] === 'web' && isset($node['hostname']) && !empty($node['hostname'])) {
            return true;
        }

        return false;
    }));

    $clients = \array_unique(\array_merge($clientsConsole, \array_map(function ($node) {
        return $node['hostname'];
    }, \array_filter($project->getAttribute('platforms', []), function ($node) {
        if (isset($node['type']) && $node['type'] === 'web' && isset($node['hostname']) && !empty($node['hostname'])) {
            return true;
        }

        return false;
    }))));

    return $clients;
}, ['request', 'console', 'project']);

App::setResource('user', function($mode, $project, $console, $request, $response, $projectDB, $consoleDB) {
    /** @var Utopia\Swoole\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Appwrite\Database\Document $project */
    /** @var Appwrite\Database\Database $consoleDB */
    /** @var Appwrite\Database\Database $projectDB */
    /** @var bool $mode */

    Authorization::setDefaultStatus(true);

    Auth::setCookieName('a_session_'.$project->getId());

    if (APP_MODE_ADMIN === $mode) {
        Auth::setCookieName('a_session_'.$console->getId());
    }

    $session = Auth::decodeSession(
        $request->getCookie(Auth::$cookieName, // Get sessions
            $request->getCookie(Auth::$cookieName.'_legacy', '')));// Get fallback session from old clients (no SameSite support)

    // Get fallback session from clients who block 3rd-party cookies
    $response->addHeader('X-Debug-Fallback', 'false');

    if(empty($session['id']) && empty($session['secret'])) {
        $response->addHeader('X-Debug-Fallback', 'true');
        $fallback = $request->getHeader('x-fallback-cookies', '');
        $fallback = \json_decode($fallback, true);
        $session = Auth::decodeSession(((isset($fallback[Auth::$cookieName])) ? $fallback[Auth::$cookieName] : ''));
    }

    Auth::$unique = $session['id'];
    Auth::$secret = $session['secret'];

    if (APP_MODE_ADMIN !== $mode) {
        $user = $projectDB->getDocument(Auth::$unique);
    }
    else {
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
            Authorization::setDefaultStatus(false);  // Cancel security segmentation for admin users.
        } else {
            $user = new Document(['$id' => '', '$collection' => Database::SYSTEM_COLLECTION_USERS]);
        }
    }

    $authJWT = $request->getHeader('x-appwrite-jwt', '');

    if (!empty($authJWT)) { // JWT authentication
        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.

        try {
            $payload = $jwt->decode($authJWT);
        } catch (JWTException $error) {
            throw new Exception('Failed to verify JWT. '.$error->getMessage(), 401);
        }
        
        $jwtUserId = $payload['userId'] ?? '';
        $jwtSessionId = $payload['sessionId'] ?? '';

        if($jwtUserId && $jwtSessionId) {
            $user = $projectDB->getDocument($jwtUserId);
        }

        if (empty($user->search('$id', $jwtSessionId, $user->getAttribute('tokens')))) { // Match JWT to active token
            $user = new Document(['$id' => '', '$collection' => Database::SYSTEM_COLLECTION_USERS]);
        }
    }

    return $user;
}, ['mode', 'project', 'console', 'request', 'response', 'projectDB', 'consoleDB']);

App::setResource('project', function($consoleDB, $request) {
    /** @var Utopia\Swoole\Request $request */
    /** @var Appwrite\Database\Database $consoleDB */

    Authorization::disable();

    $project = $consoleDB->getDocument($request->getParam('project',
        $request->getHeader('x-appwrite-project', '')));

    Authorization::reset();

    return $project;
}, ['consoleDB', 'request']);

App::setResource('console', function($consoleDB) {
    return $consoleDB->getDocument('console');
}, ['consoleDB']);

App::setResource('consoleDB', function($register) {
    $consoleDB = new Database();
    $consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
    $consoleDB->setNamespace('app_console'); // Should be replaced with param if we want to have parent projects
    $consoleDB->setMocks(Config::getParam('collections', []));

    return $consoleDB;
}, ['register']);

App::setResource('projectDB', function($register, $project) {
    $projectDB = new Database();
    $projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
    $projectDB->setNamespace('app_'.$project->getId());
    $projectDB->setMocks(Config::getParam('collections', []));

    return $projectDB;
}, ['register', 'project']);

App::setResource('mode', function($request) {
    /** @var Utopia\Swoole\Request $request */
    return $request->getParam('mode', $request->getHeader('x-appwrite-mode', APP_MODE_DEFAULT));
}, ['request']);

App::setResource('geodb', function($register) {
    /** @var Utopia\Registry\Registry $register */
    return $register->get('geodb');
}, ['register']);
