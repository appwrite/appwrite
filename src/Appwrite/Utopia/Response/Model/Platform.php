<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Platform extends Model
{
    /**
     * @var bool
     */
    protected $public = false;

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Platform ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Platform name.',
                'default' => '',
                'example' => 'My Web App',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Platform type. Possible values are: web, flutter-ios, flutter-android, ios, android, and unity.',
                'default' => '',
                'example' => 'My Web App',
            ])
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Platform Key. iOS bundle ID or Android package name.  Empty string for other platforms.',
                'default' => '',
                'example' => 'com.company.appname',
            ])
            ->addRule('store', [
                'type' => self::TYPE_STRING,
                'description' => 'App store or Google Play store ID.',
                'example' => '',
            ])
            ->addRule('hostname', [
                'type' => self::TYPE_STRING,
                'description' => 'Web app hostname. Empty string for other platforms.',
                'default' => '',
                'example' => true,
            ])
            ->addRule('httpUser', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP basic authentication username.',
                'default' => '',
                'example' => 'username',
            ])
            ->addRule('httpPass', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP basic authentication password.',
                'default' => '',
                'example' => 'password',
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Platform';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_PLATFORM;
    }
}