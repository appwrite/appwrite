<?php

namespace Appwrite\Platform\Modules\S3\Http;

use Appwrite\Event\Event;
use Appwrite\Geo\Geo;
use Appwrite\Network\Cors;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\System\System;

/**
 * The S3 routes run in the `s3` group, not `api`: they authenticate with AWS
 * Signature V4 instead of Appwrite headers, so none of the `api` hooks fire
 * for them. The module's hooks carry what the gateway still owes the
 * platform: the events queue identity here, event fan-out and network
 * metering in the shutdown hooks. Abuse limits are deliberately absent — the
 * S3 surface is API-key only.
 */
class Init extends Action
{
    public static function getName(): string
    {
        return 'init';
    }

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_INIT)
            ->groups(['s3'])
            ->desc('S3 gateway request setup')
            ->inject('request')
            ->inject('response')
            ->inject('cors')
            ->inject('project')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('usage')
            ->inject('geo')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response, Cors $cors, Document $project, User $user, Event $queueForEvents, UsageContext $usage, Geo $geo): void
    {
        $corsHeaders = $cors->headers($request->getOrigin());
        $exposedHeaders = \array_filter(\array_map('trim', \explode(',', (string) ($corsHeaders[Cors::HEADER_EXPOSE_HEADERS] ?? ''))));
        $corsHeaders[Cors::HEADER_EXPOSE_HEADERS] = \implode(', ', \array_unique(\array_merge($exposedHeaders, [
            'Accept-Ranges',
            'Content-Length',
            'Content-Range',
            'ETag',
            'Last-Modified',
            'x-amz-server-side-encryption',
        ])));
        foreach ($corsHeaders as $name => $value) {
            $response
                ->removeHeader($name)
                ->addHeader($name, $value);
        }

        // The S3 actions set the event name and params themselves; project and
        // user identify the tenant for event fan-out and for the cache-delete
        // messages the module builds from this queue.
        $queueForEvents
            ->setProject($project)
            ->setUser($user);

        // Mirrors the `api` group's "Enrich usage context with request
        // metadata" hook in app/controllers/general.php — the gateway meters
        // network usage through the same context.
        $country = '';
        $geoRecord = $geo->get($request->getIP());
        if (!$geoRecord->isEmpty()) {
            $country = \strtolower($geoRecord->getCountryCode());
        }

        $uri = $request->getURI();
        $parts = explode('/', trim($uri, '/'));
        $service = count($parts) >= 2 ? $parts[1] : $parts[0];

        $usage
                ->setPath($uri)
                ->setMethod($request->getMethod())
                ->setUserAgent($request->getUserAgent(''))
                ->setHostname($request->getOrigin('') ?: $request->getHostname())
                ->setCountry($country)
                ->setIp($request->getIP())
                ->setSdk(\strtolower($request->getHeaderLine('x-sdk-name', '')))
                ->setSdkVersion($request->getHeaderLine('x-sdk-version', ''))
                ->setRegion(System::getEnv('_APP_REGION', 'default'))
                ->setService($service);

        if (!$project->isEmpty()) {
            $usage
                    ->setTeamId((string) $project->getAttribute('teamId', ''))
                    ->setTeamInternalId((string) $project->getAttribute('teamInternalId', ''));
        }

        // Always reset — clears values carried over from a previous request
        // when this Context instance is reused across requests in the same worker.
        $usage->setResourceType('');
        $usage->setResourceId('');
        $usage->setResourceInternalId('');
        $usage->setResourcePath('');
    }
}
