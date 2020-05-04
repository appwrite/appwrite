<?php

global $utopia, $response, $projectDB;

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Utopia\Validator\Text;
use Utopia\Validator\Range;

include_once __DIR__ . '/../shared/api.php';

$utopia->post('/v1/functions')
    ->desc('Create Function')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/users/create-function.md')
    ->param('name', '', function () { return new Text(128); }, 'Function name.')
    ->param('timeout', '', function () { return new Range(1, 10); }, 'Function maximum execution time in seconds.')
    ->action(
        function ($name, $timeout) use ($response, $projectDB) {
            $function = new Document([
                '$collection' => Database::SYSTEM_COLLECTION_MEMBERSHIPS,
                '$permissions' => [
                    'read' => [],
                    'write' => [],
                ],
                'name' => $name,
                'timeout' => $timeout,
            ]);

            // $response
            //     ->setStatusCode(Response::STATUS_CODE_CREATED)
            //     ->json(array_merge($user->getArrayCopy(array_merge([
            //         '$id',
            //         'status',
            //         'email',
            //         'registration',
            //         'emailVerification',
            //         'name',
            //     ], $oauth2Keys)), ['roles' => []]));
        }
    );
