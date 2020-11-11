<?php

namespace Appwrite\Specification\Format;

use Appwrite\Specification\Format;
use Appwrite\Template\Template;

class OpenAPI3 extends Format
{
    /**
     * Get Name.
     *
     * Get format name
     *
     * @return string
     */
    public function getName():string
    {
        return 'Open API 3';
    }

    /**
     * Parse
     *
     * Parses Appwrite App to given format
     *
     * @return array
     */
    public function parse(): array
    {
        /*
        * Specifications (v3.0.0):
        * https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.0.md
        */
        $output = [
            'openapi' => '3.0.0',
            'info' => [
                'version' => $this->getParam('version'),
                'title' => $this->getParam('name'),
                'description' => $this->getParam('description'),
                'termsOfService' => $this->getParam('terms'),
                'contact' => [
                    'name' => $this->getParam('contact.name'),
                    'url' => $this->getParam('contact.url'),
                    'email' => $this->getParam('contact.email'),
                ],
                'license' => [
                    'name' => 'BSD-3-Clause',
                    'url' => 'https://raw.githubusercontent.com/appwrite/appwrite/master/LICENSE',
                ],
            ],
            'servers' => [
                [
                    'url' => $this->getParam('endpoint', ''),
                ],
            ],
            'paths' => [],
            'components' => [
                'schemas' => [],
                'securitySchemes' => $this->security,
            ],
            'externalDocs' => [
                'description' => $this->getParam('docs.description'),
                'url' => $this->getParam('docs.url'),
            ],
        ];

        if (isset($output['components']['securitySchemes']['Project'])) {
            $output['components']['securitySchemes']['Project']['x-appwrite'] = ['demo' => '5df5acd0d48c2'];
        }
        
        if (isset($output['components']['securitySchemes']['Key'])) {
            $output['components']['securitySchemes']['Key']['x-appwrite'] = ['demo' => '919c2d18fb5d4...a2ae413da83346ad2'];
        }
        
        if (isset($output['components']['securitySchemes']['Locale'])) {
            $output['components']['securitySchemes']['Locale']['x-appwrite'] = ['demo' => 'en'];
        }

        if (isset($output['components']['securitySchemes']['Mode'])) {
            $output['components']['securitySchemes']['Mode']['x-appwrite'] = ['demo' => ''];
        }

        foreach ($this->routes as $route) { /* @var $route \Utopia\Route */
            $url = \str_replace('/v1', '', $route->getURL());
            $scope = $route->getLabel('scope', '');
            $hide = $route->getLabel('sdk.hide', false);

            if ($hide) {
                continue;
            }

            $desc = (!empty($route->getLabel('sdk.description', ''))) ? \realpath(__DIR__.'/../../../'.$route->getLabel('sdk.description', '')) : null;

            $model = $route->getLabel('sdk.response.model', 'none');   

            $temp = [

                'summary' => $route->getDesc(),
                'operationId' => $route->getLabel('sdk.method', \uniqid()),
                'tags' => [$route->getLabel('sdk.namespace', 'default')],
                'description' => ($desc) ? \file_get_contents($desc) : '',
                'responses' => [
                    (string)$route->getLabel('sdk.response.code', '500') => [
                        'description' => '',
                        'content' => [
                            $route->getLabel('sdk.response.type', '') => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/'.$model,
                                ],
                            ]
                        ],
                    ],
                ],
                'requestBody' => [
                    'content' => [
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                            ],
                        ],
                    ]
                ],
                'x-appwrite' => [ // Appwrite related metadata
                    'weight' => $route->getOrder(),
                    'cookies' => $route->getLabel('sdk.cookies', false),
                    'type' => $route->getLabel('sdk.methodType', ''),
                    'demo' => 'docs/examples/'. Template::fromCamelCaseToDash($route->getLabel('sdk.namespace', 'default')).'/'.Template::fromCamelCaseToDash($temp['operationId']).'.md',
                    'edit' => 'https://github.com/appwrite/appwrite/edit/master' . $route->getLabel('sdk.description', ''),
                    'rate-limit' => $route->getLabel('abuse-limit', 0),
                    'rate-time' => $route->getLabel('abuse-time', 3600),
                    'rate-key' => $route->getLabel('abuse-key', 'url:{url},ip:{ip}'),
                    'scope' => $route->getLabel('scope', ''),
                    'platforms' => $route->getLabel('sdk.platform', []),
                ],
            ];

            if ((!empty($scope))) { //  && 'public' != $scope
                $temp['security'][] = $route->getLabel('sdk.security', $this->security);
            }

            foreach ($route->getParams() as $name => $param) {
                $validator = (\is_callable($param['validator'])) ? call_user_func_array($param['validator'], $this->app->getResources($param['resources'])) : $param['validator']; /* @var $validator \Utopia\Validator */

                $node = [
                    'name' => $name,
                    'description' => $param['description'],
                    'required' => !$param['optional'],
                ];

                switch ((!empty($validator)) ? \get_class($validator) : '') {
                    case 'Utopia\Validator\Text':
                        $node['type'] = 'string';
                        $node['x-example'] = '['.\strtoupper(Template::fromCamelCaseToSnake($node['name'])).']';
                        break;
                    case 'Utopia\Validator\Boolean':
                        $node['type'] = 'boolean';
                        $node['x-example'] = false;
                        break;
                    case 'Appwrite\Database\Validator\UID':
                        $node['type'] = 'string';
                        $node['x-example'] = '['.\strtoupper(Template::fromCamelCaseToSnake($node['name'])).']';
                        break;
                    case 'Utopia\Validator\Email':
                        $node['type'] = 'string';
                        $node['format'] = 'email';
                        $node['x-example'] = 'email@example.com';
                        break;
                    case 'Utopia\Validator\URL':
                        $node['type'] = 'string';
                        $node['format'] = 'url';
                        $node['x-example'] = 'https://example.com';
                        break;
                    case 'Utopia\Validator\JSON':
                    case 'Utopia\Validator\Mock':
                    case 'Utopia\Validator\Assoc':
                        $node['type'] = 'object';
                        $node['type'] = 'object';
                        $node['x-example'] = '{}';
                        //$node['format'] = 'json';
                        break;
                    case 'Appwrite\Storage\Validator\File':
                        $requestType = 'multipart/form-data';
                        $node['type'] = 'file';
                        break;
                    case 'Utopia\Validator\ArrayList':
                        $node['type'] = 'array';
                        $node['collectionFormat'] = 'multi';
                        $node['items'] = [
                            'type' => 'string',
                        ];
                        break;
                    case 'Appwrite\Auth\Validator\Password':
                        $node['type'] = 'string';
                        $node['format'] = 'format';
                        $node['x-example'] = 'password';
                        break;
                    case 'Utopia\Validator\Range': /* @var $validator \Utopia\Validator\Range */
                        $node['type'] = 'integer';
                        $node['format'] = 'int32';
                        $node['x-example'] = $validator->getMin();
                        break;
                    case 'Utopia\Validator\Numeric':
                        $node['type'] = 'integer';
                        $node['format'] = 'int32';
                        break;
                    case 'Utopia\Validator\Length':
                        $node['type'] = 'string';
                        break;
                    case 'Utopia\Validator\Host':
                        $node['type'] = 'string';
                        $node['format'] = 'url';
                        $node['x-example'] = 'https://example.com';
                        break;
                    case 'Utopia\Validator\WhiteList': /* @var $validator \Utopia\Validator\WhiteList */
                        $node['type'] = 'string';
                        $node['x-example'] = $validator->getList()[0];
                        break;
                    default:
                        $node['type'] = 'string';
                        break;
                }

                if ($param['optional'] && !\is_null($param['default'])) { // Param has default value
                    $node['default'] = $param['default'];
                }

                if (false !== \strpos($url, ':'.$name)) { // Param is in URL path
                    $node['in'] = 'path';
                    $temp['parameters'][] = $node;
                } elseif ($route->getMethod() == 'GET') { // Param is in query
                    $node['in'] = 'query';
                    $temp['parameters'][] = $node;
                } else { // Param is in payload
                    $node['in'] = 'formData';
                    $temp['parameters'][] = $node;
                    $requestBody['content']['application/x-www-form-urlencoded']['schema']['properties'][] = $node;

                    if (!$param['optional']) {
                        $requestBody['content']['application/x-www-form-urlencoded']['required'][] = $name;
                    }
                }

                $url = \str_replace(':'.$name, '{'.$name.'}', $url);
            }

            $output['paths'][$url][\strtolower($route->getMethod())] = $temp;
        }

        foreach ($this->models as $model) {
            $required = $model->getRequired();
            $rules = $model->getRules();

            $output['components']['schemas'][$model->getType()] = [
                'type' => 'object',
                'properties' => (empty($rules)) ? new \stdClass : [],
            ];

            if($model->isAny()) {
                $output['components']['schemas'][$model->getType()]['additionalProperties'] = true;
            }
            
            if(!empty($required)) {
                $output['components']['schemas'][$model->getType()]['required'] = $required;
            }

            foreach($model->getRules() as $name => $rule) {
                $type = '';
                $format = null;
                $items = null;

                switch ($rule['type']) {
                    case 'string':
                    case 'json':
                        $type = 'string';
                        break;
                    
                    case 'integer':
                        $type = 'integer';
                        $format = 'int32';
                        break;

                    case 'float':
                        $type = 'number';
                        $format = 'float';
                        break;
                    
                    case 'boolean':
                        $type = 'string';
                        break;
                    
                    default:
                        $type = 'object';
                        $rule['type'] = ($rule['type']) ? $rule['type'] : 'none';

                        $items = [
                            'type' => $type,
                            '$ref' => '#/components/schemas/'.$rule['type'],
                        ];
                        break;
                }

                if($rule['array']) {
                    $output['components']['schemas'][$model->getType()]['properties'][$name] = [
                        'type' => 'array',
                        'description' => $rule['description'] ?? '',
                        'items' => [
                            'type' => $type,
                        ]
                    ];

                    if($format) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['items']['format'] = $format;
                    }

                    if($items) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['items'] = $items;
                    }
                } else {
                    $output['components']['schemas'][$model->getType()]['properties'][$name] = [
                        'type' => $type,
                        'description' => $rule['description'] ?? '',
                    ];

                    if($format) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['format'] = $format;
                    }

                    if($items) {
                        $output['components']['schemas'][$model->getType()]['properties'][$name]['items'] = $items;
                    }
                }
            }
        }

        \ksort($output['paths']);

        return $output;
    }
}
