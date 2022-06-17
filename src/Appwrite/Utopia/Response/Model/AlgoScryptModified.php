<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class AlgoScryptModified extends Model
{
    public function __construct()
    {
        $this
            ->addRule('salt', [
                'type' => self::TYPE_STRING,
                'description' => 'Salt used to compute hash.',
                'default' => '',
                'example' => 'UxLMreBr6tYyjQ==',
            ])
            ->addRule('saltSeparator', [
                'type' => self::TYPE_STRING,
                'description' => 'Separator used to compute hash.',
                'default' => '',
                'example' => 'Bw==',
            ])
            ->addRule('signerKey', [
                'type' => self::TYPE_STRING,
                'description' => 'Key used to compute hash.',
                'default' => '',
                'example' => 'XyEKE9RcTDeLEsL/RjwPDBv/RqDl8fb3gpYEOQaPihbxf1ZAtSOHCjuAAa7Q3oHpCYhXSN9tizHgVOwn6krflQ==',
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
        return 'AlgoScryptModified';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ALGO_SCRYPT_MODIFIED;
    }
}
