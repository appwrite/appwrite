<?php
// Prints {provider: {secret, scopes, url}} for every OAuth2 provider without PKCE.
require '/usr/src/code/vendor/autoload.php';

use Utopia\Config\Config;

$providers = include '/usr/src/code/app/config/oAuthProviders.php';
$client = new \Utopia\Client\Client(new \Utopia\Client\Adapter\Curl\Client());
$pkce = ['appwrite', 'cloudflare', 'etsy', 'kick', 'resend', 'x'];
$state = ['success' => 'http://localhost/s?a=1', 'failure' => 'http://localhost/f', 'token' => false, 'nonce' => '0123456789abcdef0123456789abcdef', 'state' => 'app'];
$secrets = [
    'plain-secret',
    json_encode([
        'clientSecret' => 'cs',
        'auth0Domain' => 'a.auth0.com',
        'authentikDomain' => 'auth.example.com',
        'fusionAuthDomain' => 'fa.example.com',
        'keycloakDomain' => 'kc.example.com',
        'keycloakRealm' => 'my realm',
        'tenantID' => 'common',
        'oktaDomain' => 'dev.okta.com',
        'authorizationServerId' => 'aus1',
        'authorizationEndpoint' => 'https://oidc.example.com/auth',
        'endpoint' => 'https://git.example.com/',
        'prompt' => ['consent', 'login'],
        'maxAge' => '300',
    ]),
];
$out = [];
foreach ($providers as $key => $provider) {
    if (in_array($key, $pkce, true)) {
        continue;
    }
    foreach ($secrets as $i => $secret) {
        foreach ([[], ['extra', 'profile']] as $scopes) {
            try {
                $adapter = new $provider['class']($client, 'appid', $secret, 'http://localhost:9501/v1/account/sessions/oauth2/callback/' . $key . '/p1', $state, $scopes);
                $url = $adapter->getLoginURL();
            } catch (\Throwable $e) {
                $url = 'ERROR';
            }
            $out[] = ['provider' => $key, 'secret' => $secret, 'scopes' => $scopes, 'url' => $url];
        }
    }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
