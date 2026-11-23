<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Certificates;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Assoc;
use Utopia\Validator\Range;

class XList extends Base
{
    private const array ALLOWED_QUERY = [
        'filter' => ['certificateType'],
    ];

    public static function getName(): string
    {
        return 'listDistributionAppleCertificates';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/certificates')
            ->desc('List App Store Connect certificates')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('filter', [], new Assoc(), 'Filters, as `filter[certificateType]`, a comma-separated list.', true)
            ->param('limit', 20, new Range(1, 200), 'Maximum number of certificates to return.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function action(array $filter, int $limit, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $filter = \array_intersect_key($filter, \array_flip(self::ALLOWED_QUERY['filter']));

        $team = $this->provisioning($response, $cache, $key['issuerId']);

        if ($team === null) {
            return;
        }

        $types = $filter['certificateType'] ?? '';

        if (!\is_string($types)) {
            $this->error(
                $response,
                Response::STATUS_CODE_BAD_REQUEST,
                self::CODE_PARAMETER_INVALID,
                'A parameter has an invalid value',
                'The certificate type filter must be a comma separated list.',
                ['parameter' => 'filter[certificateType]'],
            );

            return;
        }

        $types = \array_values(\array_filter(\explode(',', $types), fn (string $type): bool => $type !== ''));
        $certificates = \array_values(\array_filter(
            $this->certificates($cache, $team['teamId']),
            fn (array $certificate): bool => $types === [] || \in_array($certificate['attributes']['certificateType'], $types, true),
        ));

        $this->respond($response, [
            'data' => \array_slice($certificates, 0, $limit),
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
