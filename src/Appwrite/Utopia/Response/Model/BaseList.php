<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model;

class BaseList extends Model
{
    /**
     * @var string
     */
    protected string $name = '';

    /**
     * @var string
     */
    protected string $type = '';

    /**
     * @param string $name
     * @param string $type
     * @param string $key
     * @param string $model
     * @param bool $paging
     * @param bool $public
     */
    public function __construct(string $name, string $type, string $key, string $model, bool $paging = true, bool $public = true)
    {
        $this->name = $name;
        $this->type = $type;
        $this->public = $public;

        if ($paging) {
            $namesWithCap = [
                'documents', 'collections', 'users', 'files', 'buckets', 'functions',
                'deployments', 'executions', 'projects', 'webhooks', 'keys',
                'platforms', 'rules', 'memberships', 'teams'
            ];

            if (\in_array($name, $namesWithCap)) {
                $description = 'Total number of ' . $key . ' documents that matched your query used as reference for offset pagination. When the `total` number of ' . $key . ' documents available is greater than 5000, total returned will be capped at 5000, and cursor pagination should be used. Read more about [pagination](https://appwrite.io/docs/pagination).';
            } else {
                $description = 'Total number of ' . $key . ' documents that matched your query.';
            }

            $this->addRule('total', [
                'type' => self::TYPE_INTEGER,
                'description' => $description,
                'default' => 0,
                'example' => 5,
            ]);
        }
        $this->addRule($key, [
            'type' => $model,
            'description' => 'List of ' . $key . '.',
            'default' => [],
            'array' => true,
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }
}
