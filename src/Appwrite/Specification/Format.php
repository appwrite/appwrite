<?php

namespace Appwrite\Specification;

use Utopia\App;
use Utopia\Route;
use Appwrite\Utopia\Response\Model;

abstract class Format
{
    /**
     * @var App
     */
    protected $app;
    
    /**
     * @var array
     */
    protected $services;
    
    /**
     * @var Route[]
     */
    protected $routes;
    
    /**
     * @var Model[]
     */
    protected $models;
    
    /**
     * @var array
     */
    protected $keys;
    
    /**
     * @var array
     */
    protected $security;
    
    /**
     * @var array
     */
    protected $params = [
        'name' => '',
        'description' => '',
        'endpoint' => 'https://localhost',
        'version' => '1.0.0',
        'terms' => '',
        'support.email' => '',
        'support.url' => '',
        'contact.name' => '',
        'contact.email' => '',
        'contact.url' => '',
        'license.name' => '',
        'license.url' => '',
    ];

    /**
     * @param App $app
     * @param array $services
     * @param Route[] $routes
     * @param Model[] $models
     * @param array $keys
     * @param array $security
     */
    public function __construct(App $app, array $services, array $routes, array $models, array $keys, array $security)
    {
        $this->app = $app;
        $this->services = $services;
        $this->routes = $routes;
        $this->models = $models;
        $this->keys = $keys;
        $this->security = $security;
    }

    /**
     * Get Name.
     *
     * Get format name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Parse
     *
     * Parses Appwrite App to given format
     *
     * @return array
     */
    abstract public function parse(): array;

    /**
     * Set Param.
     *
     * Set param value
     *
     * @param string $key
     * @param string $value
     * 
     * @return self
     */
    public function setParam(string $key, string $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Get Param.
     *
     * Get param value
     *
     * @param string $key
     * @param string $default
     * 
     * @return string
     */
    public function getParam(string $key, string $default = ''): string
    {
        if(!isset($this->params[$key])) {
            return $default;
        }

        return $this->params[$key];
    }

}
