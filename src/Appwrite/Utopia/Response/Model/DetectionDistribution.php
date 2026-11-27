<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class DetectionDistribution extends Detection
{
    public function __construct()
    {
        $this->conditions = [
            'type' => 'distribution',
        ];

        parent::__construct('distribution');

        $this
            ->addRule('framework', [
                'type' => self::TYPE_STRING,
                'description' => 'Mobile framework: flutter, react-native, android or ios; empty when nothing is detected.',
                'default' => '',
                'example' => 'flutter',
            ]);
    }

    public function getName(): string
    {
        return 'DetectionDistribution';
    }

    public function getType(): string
    {
        return Response::MODEL_DETECTION_DISTRIBUTION;
    }
}
