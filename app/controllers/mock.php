<?php

global $utopia, $request, $response;

use Utopia\Validator\Numeric;
use Utopia\Validator\Text;
use Utopia\Validator\ArrayList;
use Utopia\Response;
use Utopia\Validator\Host;
use Appwrite\Storage\Validator\File;

$result = [];

$utopia->get('/v1/mock/tests/foo')
    ->desc('Mock a get request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'get')
    ->label('sdk.description', 'Mock a get request for SDK tests')
    ->param('x', '', function () { return new Text(100); }, 'Sample string param')
    ->param('y', '', function () { return new Numeric(); }, 'Sample numeric param')
    ->param('z', null, function () { return new ArrayList(new Text(256)); }, 'Sample array param')
    ->action(
        function ($x, $y, $z) {
        }
    );

$utopia->post('/v1/mock/tests/foo')
    ->desc('Mock a post request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'post')
    ->label('sdk.description', 'Mock a post request for SDK tests')
    ->param('x', '', function () { return new Text(100); }, 'Sample string param')
    ->param('y', '', function () { return new Numeric(); }, 'Sample numeric param')
    ->param('z', null, function () { return new ArrayList(new Text(256)); }, 'Sample array param')
    ->action(
        function ($x, $y, $z) {
        }
    );

$utopia->patch('/v1/mock/tests/foo')
    ->desc('Mock a patch request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'patch')
    ->label('sdk.description', 'Mock a get request for SDK tests')
    ->param('x', '', function () { return new Text(100); }, 'Sample string param')
    ->param('y', '', function () { return new Numeric(); }, 'Sample numeric param')
    ->param('z', null, function () { return new ArrayList(new Text(256)); }, 'Sample array param')
    ->action(
        function ($x, $y, $z) {
        }
    );

$utopia->put('/v1/mock/tests/foo')
    ->desc('Mock a put request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'put')
    ->label('sdk.description', 'Mock a put request for SDK tests')
    ->param('x', '', function () { return new Text(100); }, 'Sample string param')
    ->param('y', '', function () { return new Numeric(); }, 'Sample numeric param')
    ->param('z', null, function () { return new ArrayList(new Text(256)); }, 'Sample array param')
    ->action(
        function ($x, $y, $z) {
        }
    );

$utopia->delete('/v1/mock/tests/foo')
    ->desc('Mock a delete request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', 'Mock a delete request for SDK tests')
    ->param('x', '', function () { return new Text(100); }, 'Sample string param')
    ->param('y', '', function () { return new Numeric(); }, 'Sample numeric param')
    ->param('z', null, function () { return new ArrayList(new Text(256)); }, 'Sample array param')
    ->action(
        function ($x, $y, $z) {
        }
    );

$utopia->get('/v1/mock/tests/bar')
    ->desc('Mock a get request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'get')
    ->label('sdk.description', 'Mock a get request for SDK tests')
    ->param('x', '', function () { return new Text(100); }, 'Sample string param')
    ->param('y', '', function () { return new Numeric(); }, 'Sample numeric param')
    ->param('z', null, function () { return new ArrayList(new Text(256)); }, 'Sample array param')
    ->action(
        function ($x, $y, $z) {
        }
    );

$utopia->post('/v1/mock/tests/bar')
    ->desc('Mock a post request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'post')
    ->label('sdk.description', 'Mock a post request for SDK tests')
    ->param('x', '', function () { return new Text(100); }, 'Sample string param')
    ->param('y', '', function () { return new Numeric(); }, 'Sample numeric param')
    ->param('z', null, function () { return new ArrayList(new Text(256)); }, 'Sample array param')
    ->action(
        function ($x, $y, $z) {
        }
    );

$utopia->patch('/v1/mock/tests/bar')
    ->desc('Mock a patch request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'patch')
    ->label('sdk.description', 'Mock a get request for SDK tests')
    ->param('x', '', function () { return new Text(100); }, 'Sample string param')
    ->param('y', '', function () { return new Numeric(); }, 'Sample numeric param')
    ->param('z', null, function () { return new ArrayList(new Text(256)); }, 'Sample array param')
    ->action(
        function ($x, $y, $z) {
        }
    );

$utopia->put('/v1/mock/tests/bar')
    ->desc('Mock a put request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'put')
    ->label('sdk.description', 'Mock a put request for SDK tests')
    ->param('x', '', function () { return new Text(100); }, 'Sample string param')
    ->param('y', '', function () { return new Numeric(); }, 'Sample numeric param')
    ->param('z', null, function () { return new ArrayList(new Text(256)); }, 'Sample array param')
    ->action(
        function ($x, $y, $z) {
        }
    );

$utopia->delete('/v1/mock/tests/bar')
    ->desc('Mock a delete request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'bar')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', 'Mock a delete request for SDK tests')
    ->param('x', '', function () { return new Text(100); }, 'Sample string param')
    ->param('y', '', function () { return new Numeric(); }, 'Sample numeric param')
    ->param('z', null, function () { return new ArrayList(new Text(256)); }, 'Sample array param')
    ->action(
        function ($x, $y, $z) {
        }
    );

$utopia->post('/v1/mock/tests/general/upload')
    ->desc('Mock a post request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'upload')
    ->label('sdk.description', 'Mock a delete request for SDK tests')
    ->label('sdk.consumes', 'multipart/form-data')
    ->param('x', '', function () { return new Text(100); }, 'Sample string param')
    ->param('y', '', function () { return new Numeric(); }, 'Sample numeric param')
    ->param('z', null, function () { return new ArrayList(new Text(256)); }, 'Sample array param')
    ->param('file', [], function () { return new File(); }, 'Sample file param', false)
    ->action(
        function ($x, $y, $z, $file) use ($request) {
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
        }
    );

$utopia->get('/v1/mock/tests/general/redirect')
    ->desc('Mock a post request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'redirect')
    ->label('sdk.description', 'Mock a redirect request for SDK tests')
    ->action(
        function () use ($response) {
            $response->redirect('/v1/mock/tests/general/redirected');
        }
    );

$utopia->get('/v1/mock/tests/general/redirected')
    ->desc('Mock a post request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'redirected')
    ->label('sdk.description', 'Mock a redirected request for SDK tests')
    ->action(
        function () {
        }
    );

$utopia->get('/v1/mock/tests/general/set-cookie')
    ->desc('Mock a cookie request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'setCookie')
    ->label('sdk.description', 'Mock a set cookie request for SDK tests')
    ->action(
        function () use ($response) {
            $response->addCookie('cookieName', 'cookieValue', \time() + 31536000, '/', 'localhost', true, true);
        }
    );

$utopia->get('/v1/mock/tests/general/get-cookie')
    ->desc('Mock a cookie request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'getCookie')
    ->label('sdk.description', 'Mock a get cookie request for SDK tests')
    ->action(
        function () use ($request) {
            if ($request->getCookie('cookieName', '') !== 'cookieValue') {
                throw new Exception('Missing cookie value', 400);
            }
        }
    );

$utopia->get('/v1/mock/tests/general/empty')
    ->desc('Mock a post request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'general')
    ->label('sdk.method', 'empty')
    ->label('sdk.description', 'Mock a redirected request for SDK tests')
    ->action(
        function () use ($response) {
            $response->noContent();
            exit();
        }
    );

$utopia->get('/v1/mock/tests/general/oauth2')
    ->desc('Mock an OAuth2 login route')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('client_id', '', function () { return new Text(100); }, 'OAuth2 Client ID.')
    ->param('redirect_uri', '', function () { return new Host(['localhost']); }, 'OAuth2 Redirect URI.') // Important to deny an open redirect attack
    ->param('scope', '', function () { return new Text(100); }, 'OAuth2 scope list.')
    ->param('state', '', function () { return new Text(1024); }, 'OAuth2 state.')
    ->action(
        function ($clientId, $redirectURI, $scope, $state) use ($response) {
            $response->redirect($redirectURI.'?'.\http_build_query(['code' => 'abcdef', 'state' => $state]));
        }
    );

$utopia->get('/v1/mock/tests/general/oauth2/token')
    ->desc('Mock an OAuth2 login route')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('client_id', '', function () { return new Text(100); }, 'OAuth2 Client ID.')
    ->param('redirect_uri', '', function () { return new Host(['localhost']); }, 'OAuth2 Redirect URI.')
    ->param('client_secret', '', function () { return new Text(100); }, 'OAuth2 scope list.')
    ->param('code', '', function () { return new Text(100); }, 'OAuth2 state.')
    ->action(
        function ($clientId, $redirectURI, $clientSecret, $code) use ($response) {
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
        }
    );

$utopia->get('/v1/mock/tests/general/oauth2/user')
    ->desc('Mock an OAuth2 user route')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('token', '', function () { return new Text(100); }, 'OAuth2 Access Token.')
    ->action(
        function ($token) use ($response) {
            if ($token != '123456') {
                throw new Exception('Invalid token');
            }

            $response->json([
                'id' => 1,
                'name' => 'User Name',
                'email' => 'user@localhost.test',
            ]);
        }
    );

$utopia->get('/v1/mock/tests/general/oauth2/success')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(
        function () use ($response) {
            $response->json([
                'result' => 'success',
            ]);
        }
    );

$utopia->get('/v1/mock/tests/general/oauth2/failure')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(
        function () use ($response) {
            $response
                ->setStatusCode(Response::STATUS_CODE_BAD_REQUEST)
                ->json([
                    'result' => 'failure',
                ]);
        }
    );

$utopia->shutdown(function() use ($response, $request, &$result, $utopia) {
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
});