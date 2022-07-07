<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use stdClass;
use Utopia\Database\Document;

class Func extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Function ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Function creation date in Datetime',
                'default' => '',
                'example' => '1975-12-06 13:30:59',
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Function update date in Datetime',
                'default' => '',
                'example' => '1975-12-06 13:30:59',
            ])
            ->addRule('execute', [
                'type' => self::TYPE_STRING,
                'description' => 'Execution permissions.',
                'default' => [],
                'example' => 'role:member',
                'array' => true,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Function name.',
                'default' => '',
                'example' => 'My Function',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Function status. Possible values: `disabled`, `enabled`',
                'default' => '',
                'example' => 'enabled',
            ])
            ->addRule('runtime', [
                'type' => self::TYPE_STRING,
                'description' => 'Function execution runtime.',
                'default' => '',
                'example' => 'python-3.8',
            ])
            ->addRule('deployment', [
                'type' => self::TYPE_STRING,
                'description' => 'Function\'s active deployment ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('vars', [
                'type' => self::TYPE_JSON,
                'description' => 'Function environment variables.',
                'default' => new \stdClass(),
                'example' => ['key' => 'value'],
            ])
            ->addRule('events', [
                'type' => self::TYPE_STRING,
                'description' => 'Function trigger events.',
                'default' => [],
                'example' => 'account.create',
                'array' => true,
            ])
            ->addRule('schedule', [
                'type' => self::TYPE_STRING,
                'description' => 'Function execution schedult in CRON format.',
                'default' => '',
                'example' => '5 4 * * *',
            ])
            ->addRule('scheduleNext', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Function next scheduled execution date in Datetime.',
                'default' => '',
                'example' => '1975-12-06 13:30:59',
            ])
            ->addRule('schedulePrevious', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Function Previous scheduled execution date in Datetime.',
                'default' => '',
                'example' => '1975-12-06 13:30:59',
            ])
            ->addRule('timeout', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Function execution timeout in seconds.',
                'default' => 15,
                'example' => 1592981237,
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Function';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_FUNCTION;
    }

    /**
     * Filter Function
     *
     * Automatically converts a [] default to a stdClass, this is called while grabbing the document.
     *
     * @param Document $document
     * @return Document
     */
    public function filter(Document $document): Document
    {
        $vars = $document->getAttribute('vars');
        if ($vars instanceof Document) {
            $vars = $vars->getArrayCopy();
        }

        if (is_array($vars) && empty($vars)) {
            $document->setAttribute('vars', new stdClass());
        }
        return $document;
    }
}
