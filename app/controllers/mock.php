<?php

global $utopia, $request, $response;

use Utopia\Validator\Numeric;
use Utopia\Validator\Text;

$result = [];

$utopia->get('/v1/mock/tests/foo')
    ->desc('Mock a get request for SDK tests')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'foo')
    ->label('sdk.method', 'get')
    ->label('sdk.description', 'Mock a get request for SDK tests')
    ->param('x', '', function () { return new Text(100); }, 'Sample string param')
    ->param('y', '', function () { return new Numeric(); }, 'Sample numeric param')
    ->action(
        function ($x, $y) {
            if(empty($x)) {
                throw new Exception('Missing parameter \'x\'', 400);
            }
        
            if(empty($y)) {
                throw new Exception('Missing parameter \'y\'', 400);
            }
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
    ->action(
        function ($x, $y) {
            if(empty($x)) {
                throw new Exception('Missing parameter \'x\'', 400);
            }
        
            if(empty($y)) {
                throw new Exception('Missing parameter \'y\'', 400);
            }
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
    ->action(
        function ($x, $y) {
            if(empty($x)) {
                throw new Exception('Missing parameter \'x\'', 400);
            }
        
            if(empty($y)) {
                throw new Exception('Missing parameter \'y\'', 400);
            }
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
    ->action(
        function ($x, $y) {
            if(empty($x)) {
                throw new Exception('Missing parameter \'x\'', 400);
            }
        
            if(empty($y)) {
                throw new Exception('Missing parameter \'y\'', 400);
            }
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
    ->action(
        function ($x, $y) {
            if(empty($x)) {
                throw new Exception('Missing parameter \'x\'', 400);
            }
        
            if(empty($y)) {
                throw new Exception('Missing parameter \'y\'', 400);
            }
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
    ->action(
        function ($x, $y) {
            if(empty($x)) {
                throw new Exception('Missing parameter \'x\'', 400);
            }
        
            if(empty($y)) {
                throw new Exception('Missing parameter \'y\'', 400);
            }
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
    ->action(
        function ($x, $y) {
            if(empty($x)) {
                throw new Exception('Missing parameter \'x\'', 400);
            }
        
            if(empty($y)) {
                throw new Exception('Missing parameter \'y\'', 400);
            }
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
    ->action(
        function ($x, $y) {
            if(empty($x)) {
                throw new Exception('Missing parameter \'x\'', 400);
            }
        
            if(empty($y)) {
                throw new Exception('Missing parameter \'y\'', 400);
            }
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
    ->action(
        function ($x, $y) {
            if(empty($x)) {
                throw new Exception('Missing parameter \'x\'', 400);
            }
        
            if(empty($y)) {
                throw new Exception('Missing parameter \'y\'', 400);
            }
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
    ->action(
        function ($x, $y) {
            if(empty($x)) {
                throw new Exception('Missing parameter \'x\'', 400);
            }
        
            if(empty($y)) {
                throw new Exception('Missing parameter \'y\'', 400);
            }
        }
    );

$utopia->get('/v1/mock/tests/report')
    ->desc('Get SDK tests report')
    ->label('scope', 'public')
    ->action(
        function () use ($response) {
 
            $path   = '/storage/cache/tests.json';
            $tests  = (file_exists($path)) ? json_decode(file_get_contents($path), true) : [];
            
            if(!is_array($tests)) {
                throw new Exception('Failed to read results', 500);
            }
        
            if(!file_put_contents($path, json_encode([]), LOCK_EX)) {
                throw new Exception('Failed to save resutls', 500);
            }

            $response->json($tests);
        }
    );

$utopia->shutdown(function() use ($response, $request, &$result, $utopia) {
    
    $route  = $utopia->match($request);
    $path   = '/storage/cache/tests.json';
    $tests  = (file_exists($path)) ? json_decode(file_get_contents($path), true) : [];
    
    if(!is_array($tests)) {
        throw new Exception('Failed to read results', 500);
    }

    $result[$route->getMethod() . ':' . $route->getURL()] = true;

    $tests = array_merge($tests, $result);

    if(!file_put_contents($path, json_encode($tests), LOCK_EX)) {
        throw new Exception('Failed to save resutls', 500);
    }

    $response->json(['result' => $route->getMethod() . ':' . $route->getURL() . ':passed']);
});