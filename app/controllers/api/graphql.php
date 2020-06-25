<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

global $utopia, $request, $response;

/**
 * TODO:
 *  1. Map all objects, object-params, object-fields
 *  2. Parse GraphQL request payload (use: https://github.com/webonyx/graphql-php)
 *  3. Route request to relevant controllers (of REST API?) / resolvers and aggergate data
 *  4. Handle errors if any
 *  5. Returen JSON response
 *  6. Write tests!
 * 
 * Demo
 *  curl -H "Content-Type: application/json" http://localhost/v1/graphql -d '{"query": "query { echo(message: \"Hello World\") }" }'
 *  
 * Explorers:
 *  - https://shopify.dev/tools/graphiql-admin-api
 *  - https://developer.github.com/v4/explorer/
 *  - http://localhost:4000
 * 
 * Docs
 *  - Overview
 *  - Clients
 * 
 *  - Queries
 *  - Mutations
 * 
 *  - Objects
 */

$utopia->post('/v1/graphql')
    ->desc('GraphQL Endpoint')
    ->groups(['api', 'graphql'])
    ->label('scope', 'public')
    ->action(
        function () use ($request, $response, $utopia) {

            foreach ($utopia->getRoutes() as $method => $routes) {
                var_dump($method);

                foreach ($routes as $key => $route) {
                    var_dump($key);
                }
            }

            $userType = new ObjectType([
                'name' => 'User',
                'fields' => [
                    'name' => [
                        'type' => Type::string(),
                    ],
                ],
            ]);

            $queryType = new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'echo' => [
                        'type' => Type::string(),
                        'args' => [
                            'message' => ['type' => Type::string()],
                        ],
                        'resolve' => function ($rootValue, $args) {
                            return $rootValue['prefix'] . $args['message'];
                        }
                    ],
                    'users' => [
                        'type' => Type::listOf($userType),
                        //'type' => $userType,
                        'args' => [
                            'message' => ['type' => Type::string()],
                        ],
                        'resolve' => function ($rootValue, $args) {
                            return ['name' => 'Eldad Fux'];
                            return [
                                ['name' => 'Eldad Fux'],
                                ['name' => 'Sharon Kapon'],
                            ];
                        }
                    ],
                ],
            ]);

            $mutationType = new ObjectType([
                'name' => 'Mutation',
                'fields' => [
                    'sum' => [
                        'type' => Type::int(),
                        'args' => [
                            'x' => ['type' => Type::int()],
                            'y' => ['type' => Type::int()],
                        ],
                        'resolve' => function ($calc, $args) {
                            return $args['x'] + $args['y'];
                        },
                    ],
                ],
            ]);

            $schema = new Schema([
                'query' => $queryType,
                'mutation' => $mutationType,
            ]);

            $query = $request->getPayload('query', '');
            $variables = $request->getPayload('variables', null);

            try {
                $rootValue = [];
                $result = GraphQL::executeQuery($schema, $query, $rootValue, null, $variables);
                $output = $result->toArray();
            } catch (\Exception $error) {
                $output = [
                    'errors' => [
                        [
                            'message' => $error->getMessage().'xxx',
                            'code' => $error->getCode(),
                            'file' => $error->getFile(),
                            'line' => $error->getLine(),
                            'trace' => $error->getTrace(),
                        ]
                    ]
                ];
            }
            
            $response->json($output);
            echo "\n"; //TODO REMOVE THIS
        }
    );