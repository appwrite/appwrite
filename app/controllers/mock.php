<?php

global $utopia, $request, $response;

use Utopia\Database\Document;
use Appwrite\Network\Validator\Host;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;
use Utopia\Storage\Validator\File;

App::get('/v1/mock/tests/foo')
    ->desc('Get Foo')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'get')
    ->label('sdk.description', 'Mock a get request.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Integer(true), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::post('/v1/mock/tests/foo')
    ->desc('Post Foo')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'post')
    ->label('sdk.description', 'Mock a post request.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Integer(true), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::patch('/v1/mock/tests/foo')
    ->desc('Patch Foo')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'patch')
    ->label('sdk.description', 'Mock a patch request.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Integer(true), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::put('/v1/mock/tests/foo')
    ->desc('Put Foo')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'put')
    ->label('sdk.description', 'Mock a put request.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Integer(true), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::delete('/v1/mock/tests/foo')
    ->desc('Delete Foo')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', 'Mock a delete request.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Integer(true), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($x, $y, $z) {
    });

App::get('/v1/mock/tests/bar')
    ->desc('Get Bar')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'get')
    ->label('sdk.description', 'Mock a get request.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->param('required', '', new Text(100), 'Sample string param')
    ->param('default', '', new Integer(true), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($required, $default, $z) {
    });

App::post('/v1/mock/tests/bar')
    ->desc('Post Bar')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'post')
    ->label('sdk.description', 'Mock a post request.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->param('required', '', new Text(100), 'Sample string param')
    ->param('default', '', new Integer(true), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($required, $default, $z) {
    });

App::patch('/v1/mock/tests/bar')
    ->desc('Patch Bar')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'patch')
    ->label('sdk.description', 'Mock a patch request.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->param('required', '', new Text(100), 'Sample string param')
    ->param('default', '', new Integer(true), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($required, $default, $z) {
    });

App::put('/v1/mock/tests/bar')
    ->desc('Put Bar')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'put')
    ->label('sdk.description', 'Mock a put request.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->param('required', '', new Text(100), 'Sample string param')
    ->param('default', '', new Integer(true), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($required, $default, $z) {
    });

App::delete('/v1/mock/tests/bar')
    ->desc('Delete Bar')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', 'Mock a delete request.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->param('required', '', new Text(100), 'Sample string param')
    ->param('default', '', new Integer(true), 'Sample numeric param')
    ->param('z', null, new ArrayList(new Text(256)), 'Sample array param')
    ->action(function ($required, $default, $z) {
    });

App::get('/v1/mock/tests/general/download')
    ->desc('Download File')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'download')
    ->label('sdk.methodType', 'location')
    ->label('sdk.description', 'Mock a file download request.')
    ->label('sdk.response.type', '*/*')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.mock', true)
    ->inject('response')
    ->action(function ($response) {
        /** @var Utopia\Swoole\Request $request */
        
        $response
            ->setContentType('text/plain')
            ->addHeader('Content-Disposition', 'attachment; filename="test.txt"')
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)).' GMT') // 45 days cache
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->send("Download test passed.")
        ;
    });

App::post('/v1/mock/tests/general/upload')
    ->desc('Upload File')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'upload')
    ->label('sdk.description', 'Mock a file upload request.')
    ->label('sdk.request.type', 'multipart/form-data')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->param('x', '', new Text(100), 'Sample string param')
    ->param('y', '', new Integer(true), 'Sample numeric param')
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
    ->desc('Redirect')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'redirect')
    ->label('sdk.description', 'Mock a redirect request.')
    ->label('sdk.response.code', Response::STATUS_CODE_MOVED_PERMANENTLY)
    ->label('sdk.response.type', Response::CONTENT_TYPE_HTML)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->redirect('/v1/mock/tests/general/redirect/done');
    });

App::get('/v1/mock/tests/general/redirect/done')
    ->desc('Redirected')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'redirected')
    ->label('sdk.description', 'Mock a redirected request.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->action(function () {
    });

App::get('/v1/mock/tests/general/set-cookie')
    ->desc('Set Cookie')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'setCookie')
    ->label('sdk.description', 'Mock a set cookie request.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->addCookie('cookieName', 'cookieValue', \time() + 31536000, '/', 'localhost', true, true);
    });

App::get('/v1/mock/tests/general/get-cookie')
    ->desc('Get Cookie')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'getCookie')
    ->label('sdk.description', 'Mock a cookie response.')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MOCK)
    ->label('sdk.mock', true)
    ->inject('request')
    ->action(function ($request) {
        /** @var Utopia\Swoole\Request $request */

        if ($request->getCookie('cookieName', '') !== 'cookieValue') {
            throw new Exception('Missing cookie value', 400);
        }
    });

App::get('/v1/mock/tests/general/empty')
    ->desc('Empty Response')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'empty')
    ->label('sdk.description', 'Mock an empty response.')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->label('sdk.mock', true)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->noContent();
    });

App::get('/v1/mock/tests/general/400-error')
    ->desc('400 Error')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'error400')
    ->label('sdk.description', 'Mock a 400 failed request.')
    ->label('sdk.response.code', Response::STATUS_CODE_BAD_REQUEST)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ERROR)
    ->label('sdk.mock', true)
    ->action(function () {
        throw new Exception('Mock 400 error', 400);
    });

App::get('/v1/mock/tests/general/500-error')
    ->desc('500 Error')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'error500')
    ->label('sdk.description', 'Mock a 500 failed request.')
    ->label('sdk.response.code', Response::STATUS_CODE_INTERNAL_SERVER_ERROR)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ERROR)
    ->label('sdk.mock', true)
    ->action(function () {
        throw new Exception('Mock 500 error', 500);
    });

App::get('/v1/mock/tests/general/502-error')
    ->desc('502 Error')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'error502')
    ->label('sdk.description', 'Mock a 502 bad gateway.')
    ->label('sdk.response.code', Response::STATUS_CODE_BAD_GATEWAY)
    ->label('sdk.response.type', Response::CONTENT_TYPE_TEXT)
    ->label('sdk.response.model', Response::MODEL_ANY)
    ->label('sdk.mock', true)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response
            ->setStatusCode(502)
            ->text('This is a text error')
        ;
    });

App::get('/v1/mock/tests/general/oauth2')
    ->desc('OAuth Login')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->label('sdk.mock', true)
    ->param('client_id', '', new Text(100), 'OAuth2 Client ID.')
    ->param('redirect_uri', '', new Host(['localhost']), 'OAuth2 Redirect URI.') // Important to deny an open redirect attack
    ->param('scope', '', new Text(100), 'OAuth2 scope list.')
    ->param('state', '', new Text(1024), 'OAuth2 state.')
    ->inject('response')
    ->action(function ($client_id, $redirectURI, $scope, $state, $response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->redirect($redirectURI.'?'.\http_build_query(['code' => 'abcdef', 'state' => $state]));
    });

App::get('/v1/mock/tests/general/oauth2/token')
    ->desc('OAuth2 Token')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->label('sdk.mock', true)
    ->param('client_id', '', new Text(100), 'OAuth2 Client ID.')
    ->param('redirect_uri', '', new Host(['localhost']), 'OAuth2 Redirect URI.')
    ->param('client_secret', '', new Text(100), 'OAuth2 scope list.')
    ->param('code', '', new Text(100), 'OAuth2 state.')
    ->inject('response')
    ->action(function ($client_id, $redirectURI, $client_secret, $code, $response) {
        /** @var Appwrite\Utopia\Response $response */

        if ($client_id != '1') {
            throw new Exception('Invalid client ID');
        }

        if ($client_secret != '123456') {
            throw new Exception('Invalid client secret');
        }

        if ($code != 'abcdef') {
            throw new Exception('Invalid token');
        }

        $response->json(['access_token' => '123456']);
    });

App::get('/v1/mock/tests/general/oauth2/user')
    ->desc('OAuth2 User')
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
    ->desc('OAuth2 Success')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->json([
            'result' => 'success',
        ]);
    });

App::get('/v1/mock/tests/general/oauth2/failure')
    ->desc('OAuth2 Failure')
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

    $result[$route->getMethod() . ':' . $route->getPath()] = true;

    $tests = \array_merge($tests, $result);

    if (!\file_put_contents($path, \json_encode($tests), LOCK_EX)) {
        throw new Exception('Failed to save results', 500);
    }

    $response->dynamic(new Document(['result' => $route->getMethod() . ':' . $route->getPath() . ':passed']), Response::MODEL_MOCK);
}, ['utopia', 'response', 'request'], 'mock');