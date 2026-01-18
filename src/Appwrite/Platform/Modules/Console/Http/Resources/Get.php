<?php

namespace Appwrite\Platform\Modules\Console\Http\Resources;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Domains\Domain as Domain;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Domain as DomainValidator;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getResource';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/console/resources')
            ->desc('Check resource ID availability')
            ->groups(['api', 'projects'])
            ->label('scope', 'rules.read')
            ->label('sdk', new Method(
                namespace: 'console',
                group: null,
                name: 'getResource',
                description: <<<EOT
                Check if a resource ID is available.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
            ))
            ->label('abuse-limit', 120)
            ->label('abuse-key', 'userId:{userId}, url:{url}')
            ->label('abuse-time', 60)
            ->param('value', '', new Text(256), 'Resource value.')
            ->param('type', '', new WhiteList(['rules']), 'Resource type.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('platform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $value,
        string $type,
        Response $response,
        Database $dbForPlatform,
        array $platform,
        Authorization $authorization,
    ) {
        $domains = $platform['hostnames'] ?? [];
        if ($type === 'rules') {
            $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
            $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');

            $restrictions = [];
            if (!empty($sitesDomain)) {
                // Ensure site domains are exactly 1 subdomain, and dont start with reserved prefix
                $domainLevel = \count(\explode('.', $sitesDomain));
                $restrictions[] = DomainValidator::createRestriction($sitesDomain, $domainLevel + 1, ['commit-', 'branch-']);
            }
            if (!empty($functionsDomain)) {
                // Ensure function domains are exactly 1 subdomain
                $domainLevel = \count(\explode('.', $functionsDomain));
                $restrictions[] = DomainValidator::createRestriction($functionsDomain, $domainLevel + 1);
            }
            $validator = new DomainValidator($restrictions);

            if (!$validator->isValid($value)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This domain name is not allowed. Please use a different domain.');
            }

            $deniedDomains = [...$domains];

            if (!empty($sitesDomain)) {
                $deniedDomains[] = $sitesDomain;
            }

            if (!empty($functionsDomain)) {
                $deniedDomains[] = $functionsDomain;
            }

            $denyListDomains = System::getEnv('_APP_CUSTOM_DOMAIN_DENY_LIST', '');
            $denyListDomains = \array_map('trim', explode(',', $denyListDomains));
            foreach ($denyListDomains as $denyListDomain) {
                if (empty($denyListDomain)) {
                    continue;
                }
                $deniedDomains[] = $denyListDomain;
            }

            if (\in_array($value, $deniedDomains)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This domain name is not allowed. Please use a different domain.');
            }

            try {
                $domain = new Domain($value);
            } catch (\Throwable) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Domain may not start with http:// or https://.');
            }

            $document = $authorization->skip(fn () => $dbForPlatform->findOne('rules', [
                Query::equal('domain', [$value]),
            ]));

            if (!$document->isEmpty()) {
                throw new Exception(Exception::RESOURCE_ALREADY_EXISTS);
            }

            $response->noContent();
        }

        // Only occurs if type is added into whitelist, but not supported in action
        throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid type');
    }
}
