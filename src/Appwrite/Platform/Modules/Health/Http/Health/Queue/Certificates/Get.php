<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Queue\Certificates;

use Appwrite\Event\Publisher\Certificate;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Base;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Validator\Integer;

class Get extends Base
{
    public static function getName(): string
    {
        return 'getQueueCertificates';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/queue/certificates')
            ->desc('Get certificates queue')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
            ->inject('publisherForCertificates')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(int|string $threshold, Certificate $publisherForCertificates, Response $response): void
    {
        $threshold = (int) $threshold;

        $size = $publisherForCertificates->getSize();

        $this->assertQueueThreshold($size, $threshold);

        $response->dynamic(new Document(['size' => $size]), Response::MODEL_HEALTH_QUEUE);
    }
}
