<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class Team extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Team ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Team creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Team update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Team name.',
                'default' => '',
                'example' => 'VIP',
            ])
            ->addRule('total', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of team members.',
                'default' => 0,
                'example' => 7,
            ])
            ->addRule('prefs', [
                'type' => Response::MODEL_PREFERENCES,
                'description' => 'Team preferences as a key-value object',
                'default' => new \stdClass(),
                'example' => ['theme' => 'pink', 'timezone' => 'UTC'],
            ])
        ;
    }

    /**
     * Process Document before returning it to the client
     *
     * @return Document
     */
    public function filter(Document $document): Document
    {
        $prefs = $document->getAttribute('prefs');
        if ($prefs instanceof Document) {
            $prefs = $prefs->getArrayCopy();
        }

        if (is_array($prefs) && empty($prefs)) {
            $document->setAttribute('prefs', new \stdClass());
        }
        return $document;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Team';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_TEAM;
    }
}
