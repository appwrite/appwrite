<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class Escalation extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Escalation ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Escalation creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Escalation update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Escalation name.',
                'default' => '',
                'example' => 'Platform',
            ])
            ->addRule('steps', [
                'type' => Response::MODEL_ESCALATION_STEP,
                'description' => 'Who is notified of a firing alert, in order, until a member acknowledges it.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('repeat', [
                'type' => self::TYPE_INTEGER,
                'description' => 'How many more times the steps run from the first, a monitor\'s cooldown after the last step, while nobody has acknowledged the alert.',
                'default' => 0,
                'example' => 2,
            ]);
    }

    public function getName(): string
    {
        return 'Escalation';
    }

    public function getType(): string
    {
        return Response::MODEL_ESCALATION;
    }

    /**
     * Steps are stored as JSON; each is presented as a document for its own model.
     */
    public function filter(Document $document): Document
    {
        $document->setAttribute('steps', \array_map(
            fn (array|Document $step) => $step instanceof Document ? $step : new Document($step),
            $document->getAttribute('steps', []),
        ));

        return $document;
    }
}
