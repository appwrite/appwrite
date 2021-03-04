<?php

global $utopia, $request, $response;

use Utopia\App;
use Utopia\Response;
use Utopia\Validator\Numeric;
use Utopia\Validator\Text;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Host;
use Utopia\Storage\Validator\File;

App::get('/v1/mock/tests/foo')
    ->desc('Mock a get request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'get')
    ->label('sdk.description', 'Mock a get request for SDK tests')
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Numeric(), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::post('/v1/mock/tests/foo')
    ->desc('Mock a post request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'post')
    ->label('sdk.description', 'Mock a post request for SDK tests')
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Numeric(), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::patch('/v1/mock/tests/foo')
    ->desc('Mock a patch request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'patch')
    ->label('sdk.description', 'Mock a get request for SDK tests')
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Numeric(), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::put('/v1/mock/tests/foo')
    ->desc('Mock a put request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'put')
    ->label('sdk.description', 'Mock a put request for SDK tests')
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Numeric(), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::delete('/v1/mock/tests/foo')
    ->desc('Mock a delete request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', 'Mock a delete request for SDK tests')
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Numeric(), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::get('/v1/mock/tests/bar')
    ->desc('Mock a get request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'get')
    ->label('sdk.description', 'Mock a get request for SDK tests')
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Numeric(), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::post('/v1/mock/tests/bar')
    ->desc('Mock a post request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'post')
    ->label('sdk.description', 'Mock a post request for SDK tests')
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Numeric(), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::patch('/v1/mock/tests/bar')
    ->desc('Mock a patch request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'patch')
    ->label('sdk.description', 'Mock a get request for SDK tests')
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Numeric(), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::put('/v1/mock/tests/bar')
    ->desc('Mock a put request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'put')
    ->label('sdk.description', 'Mock a put request for SDK tests')
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Numeric(), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::delete('/v1/mock/tests/bar')
    ->desc('Mock a delete request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', 'Mock a delete request for SDK tests')
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Numeric(), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::post('/v1/mock/tests/general/upload')
    ->desc('Mock a post request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'upload')
    ->label('sdk.description', 'Mock a delete request for SDK tests')
    ->label('sdk.request.type', 'multipart/form-data')
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Numeric(), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->param('file', [], new File(), 'Sample file param', false)
    ->inject('request')
    ->action(function ($x, $y, $z, $file, $request) {
        /** @var Utopia\Swoole\Request $request */
        
        $file = $request->getFiles('file');
        $file['tmp_name'] = (\is_array($file['tmp_name'])) ? $file['tmp_name'] : [$file['tmp_name']];
        $file['name'] = (\is_array($file['name'])) ? $file['name'] : [$file['name']];
        $file['size'] = (\is_array($file['size'])) ? $file['size'] : [$file['size']];

        foreach ($file['name'] as $i => $name) {
            if ($name !== 'file.png') {
                throw new Exception('Wrong file name', 400);
            }
        }

        foreach ($file['size'] as $i => $size) {
            if ($size !== 38756) {
                throw new Exception('Wrong file size', 400);
            }
        }

        foreach ($file['tmp_name'] as $i => $tmpName) {
            if (\md5(\file_get_contents($tmpName)) !== 'd80e7e6999a3eb2ae0d631a96fe135a4') {
                throw new Exception('Wrong file uploaded', 400);
            }
        }
    });

App::get('/v1/mock/tests/general/redirect')
    ->desc('Mock a post request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'redirect')
    ->label('sdk.description', 'Mock a redirect request for SDK tests')
    ->label('sdk.mock', true)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->redirect('/v1/mock/tests/general/redirect/done');
    });

App::get('/v1/mock/tests/general/redirect/done')
    ->desc('Mock a post request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'redirected')
    ->label('sdk.description', 'Mock a redirected request for SDK tests')
    ->label('sdk.mock', true)
    ->action(function () {
    });

App::get('/v1/mock/tests/general/set-cookie')
    ->desc('Mock a cookie request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'setCookie')
    ->label('sdk.description', 'Mock a set cookie request for SDK tests')
    ->label('sdk.mock', true)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->addCookie('cookieName', 'cookieValue', \time() + 31536000, '/', 'localhost', true, true);
    });

App::get('/v1/mock/tests/general/get-cookie')
    ->desc('Mock a cookie request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'getCookie')
    ->label('sdk.description', 'Mock a get cookie request for SDK tests')
    ->label('sdk.mock', true)
    ->inject('request')
    ->action(function ($request) {
        /** @var Utopia\Swoole\Request $request */

        if ($request->getCookie('cookieName', '') !== 'cookieValue') {
            throw new Exception('Missing cookie value', 400);
        }
    });

App::get('/v1/mock/tests/general/empty')
    ->desc('Mock a post request for SDK tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'empty')
    ->label('sdk.description', 'Mock a redirected request for SDK tests')
    ->label('sdk.mock', true)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->noContent();
    });

App::get('/v1/mock/tests/general/400-error')
    ->desc('Mock a an 400 failed request')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'error400')
    ->label('sdk.description', 'Mock an 400 error')
    ->label('sdk.mock', true)
    ->action(function () {
        throw new Exception('Mock 400 error', 400);
    });

App::get('/v1/mock/tests/general/500-error')
    ->desc('Mock a an 500 failed request')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'error500')
    ->label('sdk.description', 'Mock an 500 error')
    ->label('sdk.mock', true)
    ->action(function () {
        throw new Exception('Mock 500 error', 500);
    });

App::get('/v1/mock/tests/general/oauth2')
    ->desc('Mock an OAuth2 login route')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->label('sdk.mock', true)
    ->param('client_id', '', new Text(100), 'OAuth2 Client ID.')
    ->param('redirect_uri', '', new Host(['localhost']), 'OAuth2 Redirect URI.') // Important to deny an open redirect attack
    ->param('scope', '', new Text(100), 'OAuth2 scope list.')
    ->param('state', '', new Text(1024), 'OAuth2 state.')
    ->inject('response')
    ->action(function ($clientId, $redirectURI, $scope, $state, $response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->redirect($redirectURI.'?'.\http_build_query(['code' => 'abcdef', 'state' => $state]));
    });

App::get('/v1/mock/tests/general/oauth2/token')
    ->desc('Mock an OAuth2 login route')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->label('sdk.mock', true)
    ->param('client_id', '', new Text(100), 'OAuth2 Client ID.')
    ->param('redirect_uri', '', new Host(['localhost']), 'OAuth2 Redirect URI.')
    ->param('client_secret', '', new Text(100), 'OAuth2 scope list.')
    ->param('code', '', new Text(100), 'OAuth2 state.')
    ->inject('response')
    ->action(function ($clientId, $redirectURI, $clientSecret, $code, $response) {
        /** @var Appwrite\Utopia\Response $response */

        if ($clientId != '1') {
            throw new Exception('Invalid client ID');
        }

        if ($clientSecret != '123456') {
            throw new Exception('Invalid client secret');
        }

        if ($code != 'abcdef') {
            throw new Exception('Invalid token');
        }

        $response->json(['access_token' => '123456']);
    });

App::get('/v1/mock/tests/general/oauth2/user')
    ->desc('Mock an OAuth2 user route')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('token', '', new Text(100), 'OAuth2 Access Token.')
    ->inject('response')
    ->action(function ($token, $response) {
        /** @var Appwrite\Utopia\Response $response */

        if ($token != '123456') {
            throw new Exception('Invalid token');
        }

        $response->json([
            'id' => 1,
            'name' => 'User Name',
            'email' => 'user@localhost.test',
        ]);
    });

App::get('/v1/mock/tests/general/oauth2/success')
    ->label('scope', 'public')
    ->groups(['mock'])
    ->label('docs', false)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->json([
            'result' => 'success',
        ]);
    });

App::get('/v1/mock/tests/general/oauth2/failure')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response
            ->setStatusCode(Response::STATUS_CODE_BAD_REQUEST)
            ->json([
                'result' => 'failure',
            ]);
    });

App::shutdown(function($utopia, $response, $request) {
    /** @var Utopia\App $utopia */
    /** @var Utopia\Swoole\Request $request */
    /** @var Appwrite\Utopia\Response $response */

    $result = [];
    $route  = $utopia->match($request);
    $path   = APP_STORAGE_CACHE.'/tests.json';
    $tests  = (\file_exists($path)) ? \json_decode(\file_get_contents($path), true) : [];
    
    if (!\is_array($tests)) {
        throw new Exception('Failed to read results', 500);
    }

    $result[$route->getMethod() . ':' . $route->getURL()] = true;

    $tests = \array_merge($tests, $result);

    if (!\file_put_contents($path, \json_encode($tests), LOCK_EX)) {
        throw new Exception('Failed to save resutls', 500);
    }

    $response->json(['result' => $route->getMethod() . ':' . $route->getURL() . ':passed']);
}, ['utopia', 'response', 'request'], 'mock');