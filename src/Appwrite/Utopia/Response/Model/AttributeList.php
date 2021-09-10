<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class AttributeList extends Model
{
    public function __construct()
    {
        $this
            ->addRule('sum', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total sum of items in the list.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule('attributes', [
                'type' => Response::MODEL_ATTRIBUTE,
                'description' => 'List of attributes.',
                'default' => [],
                'array' => true,
                'getNestedType' => function(Document $attribute) {
                    return match($attribute->getAttribute('type')) {
                        self::TYPE_BOOLEAN => Response::MODEL_ATTRIBUTE_BOOLEAN,
                        self::TYPE_INTEGER => Response::MODEL_ATTRIBUTE_INTEGER,
                        self::TYPE_FLOAT => Response::MODEL_ATTRIBUTE_FLOAT,
                        self::TYPE_STRING => match($attribute->getAttribute('format')) {
                            APP_DATABASE_ATTRIBUTE_EMAIL => Response::MODEL_ATTRIBUTE_EMAIL,
                            APP_DATABASE_ATTRIBUTE_ENUM => Response::MODEL_ATTRIBUTE_ENUM,
                            APP_DATABASE_ATTRIBUTE_IP => Response::MODEL_ATTRIBUTE_IP,
                            APP_DATABASE_ATTRIBUTE_URL => Response::MODEL_ATTRIBUTE_URL,
                            default => Response::MODEL_ATTRIBUTE_STRING,
                        },
                        default => Response::MODEL_ATTRIBUTE,
                    };
                },
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
        return 'Attributes List';
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ATTRIBUTE_LIST;
    }
}