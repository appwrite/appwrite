<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Platform extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => 'string',
                'description' => 'Platform ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => 'string',
                'description' => 'Platform name.',
                'example' => 'My Web App',
            ])
            ->addRule('type', [
                'type' => 'string',
                'description' => 'Platform type. Possible values are: web, flutter-ios, flutter-android, ios, android, and unity.',
                'example' => 'My Web App',
            ])
            ->addRule('key', [
                'type' => 'string',
                'description' => 'Platform Key. iOS bundle ID or Android package name.  Empty string for other platforms.',
                'example' => 'com.company.appname',
            ])
            // ->addRule('store', [
            //     'type' => 'string',
            //     'description' => 'Link to platform store.',
            //     'example' => '',
            // ])
            ->addRule('hostname', [
                'type' => 'string',
                'description' => 'Web app hostname. Empty string for other platforms.',
                'example' => true,
            ])
            ->addRule('httpUser', [
                'type' => 'string',
                'description' => 'HTTP basic authentication username.',
                'default' => '',
                'example' => 'username',
            ])
            ->addRule('httpPass', [
                'type' => 'string',
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