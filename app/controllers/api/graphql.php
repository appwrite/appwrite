<?php

/**
 * TODO:
 *  1. Map all objects, object-params, object-fields
 *  2. Parse GraphQL request payload (use: https://github.com/webonyx/graphql-php)
 *  3. Route request to relevant controllers (of REST API?) / resolvers and aggergate data
 *  4. Handle errors if any
 *  5. Returen JSON response
 *  6. Write tests!
 */

use Appwrite\Extend\Exception;
use Utopia\App;

App::post('/v1/graphql')
    ->desc('GraphQL Endpoint')
    ->groups(['api', 'graphql'])
    ->label('scope', 'public')
    ->action(
        function () {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'GraphQL support is coming soon!', 503);
        }
    );
