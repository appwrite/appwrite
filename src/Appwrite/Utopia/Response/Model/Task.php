<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Task extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => 'string',
                'description' => 'Task ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => 'string',
                'description' => 'Task name.',
                'example' => 'My Task',
            ])
            ->addRule('security', [
                'type' => 'boolean',
                'description' => 'Indicated if SSL / TLS Certificate verification is enabled.',
                'example' => true,
            ])
            ->addRule('httpMethod', [
                'type' => 'string',
                'description' => 'Task HTTP Method.',
                'example' => 'POST',
            ])
            ->addRule('httpUrl', [
                'type' => 'string',
                'description' => 'Task HTTP URL.',
                'example' => 'https://example.com/task',
            ])
            ->addRule('httpHeaders', [
                'type' => 'string',
                'description' => 'Task HTTP headers.',
                'default' => [],
                'example' => ['key:value'],
                'array' => true,
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
            ->addRule('duration', [
                'type' => 'integer',
                'description' => 'Task duration in seconds.',
                'default' => 0,
                'example' => 1.2,
            ])
        ;
    }

    /*
    delay: 6
    failures: 5
    log: "[{"code":411,"duration":1.82,"delay":6,"errors":["Request failed with status code 411"],"headers":"HTTP\/1.1 411 Length Required\r\nContent-Type: text\/html\r\nContent-Length: 357\r\nConnection: close\r\nDate: Sat, 21 Mar 2020 21:22:08 GMT\r\nServer: ECSF (nyb\/1D33)\r\n\r\n","body":""},{"code":411,"duration":1.86,"delay":4,"errors":["Request failed with status code 411"],"headers":"HTTP\/1.1 411 Length Required\r\nContent-Type: text\/html\r\nContent-Length: 357\r\nConnection: close\r\nDate: Sat, 21 Mar 2020 21:21:06 GMT\r\nServer: ECSF (nyb\/1D32)\r\n\r\n","body":""},{"code":411,"duration":1.82,"delay":2,"errors":["Request failed with status code 411"],"headers":"HTTP\/1.1 411 Length Required\r\nContent-Type: text\/html\r\nContent-Length: 357\r\nConnection: close\r\nDate: Sat, 21 Mar 2020 21:20:04 GMT\r\nServer: ECSF (nyb\/1D0A)\r\n\r\n","body":""},{"code":411,"duration":1.49,"delay":6,"errors":["Request failed with status code 411"],"headers":"HTTP\/1.1 411 Length Required\r\nContent-Type: text\/html\r\nContent-Length: 357\r\nConnection: close\r\nDate: Sat, 21 Mar 2020 21:19:07 GMT\r\nServer: ECSF (nyb\/1D04)\r\n\r\n","body":""},{"code":411,"duration":2.18,"delay":4,"errors":["Request failed with status code 411"],"headers":"HTTP\/1.1 411 Length Required\r\nContent-Type: text\/html\r\nContent-Length: 357\r\nConnection: close\r\nDate: Sat, 21 Mar 2020 21:18:05 GMT\r\nServer: ECSF (nyb\/1D23)\r\n\r\n","body":""}]"
    schedule: "* * * * *"
    status: "pause"
    updated: 1594494053
    previous: 1584825726
    next: "1597439760"
    */

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Webhook';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_WEBHOOK;
    }
}