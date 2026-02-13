<?php

namespace Appwrite\Platform\Modules\Console\Http\Variables;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Domains\Domain;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\IP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getVariables';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/console/variables')
            ->desc('Get variables')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.read')
            ->label('sdk', new Method(
                namespace: 'console',
                group: 'console',
                name: 'variables',
                description: '/docs/references/console/variables.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_CONSOLE_VARIABLES,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->inject('response')
            ->inject('platform')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(Response $response, array $platform, Database $dbForProject)
    {
        $validator = new Domain(System::getEnv('_APP_DOMAIN_TARGET_CNAME'));
        $isCNAMEValid = !empty(System::getEnv('_APP_DOMAIN_TARGET_CNAME', '')) && $validator->isKnown() && !$validator->isTest();

        $validator = new IP(IP::V4);
        $isAValid = !empty(System::getEnv('_APP_DOMAIN_TARGET_A', '')) && ($validator->isValid(System::getEnv('_APP_DOMAIN_TARGET_A')));

        $validator = new IP(IP::V6);
        $isAAAAValid = !empty(System::getEnv('_APP_DOMAIN_TARGET_AAAA', '')) && $validator->isValid(System::getEnv('_APP_DOMAIN_TARGET_AAAA'));

        $isDomainEnabled = $isAAAAValid || $isAValid || $isCNAMEValid;

        $isVcsEnabled = !empty(System::getEnv('_APP_VCS_GITHUB_APP_NAME', ''))
            && !empty(System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY', ''))
            && !empty(System::getEnv('_APP_VCS_GITHUB_APP_ID', ''))
            && !empty(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''))
            && !empty(System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''));

        $isAssistantEnabled = !empty(System::getEnv('_APP_ASSISTANT_OPENAI_API_KEY', ''));

        $adapter = $dbForProject->getAdapter();

        $variables = new Document([
            '_APP_DOMAIN_TARGET_CNAME' => System::getEnv('_APP_DOMAIN_TARGET_CNAME'),
            '_APP_DOMAIN_TARGET_AAAA' => System::getEnv('_APP_DOMAIN_TARGET_AAAA'),
            '_APP_DOMAIN_TARGET_A' => System::getEnv('_APP_DOMAIN_TARGET_A'),
            '_APP_DOMAIN_TARGET_CAA' => '0 issue "' . System::getEnv('_APP_DOMAIN_TARGET_CAA') . '"',
            '_APP_STORAGE_LIMIT' => +System::getEnv('_APP_STORAGE_LIMIT'),
            '_APP_COMPUTE_BUILD_TIMEOUT' => +System::getEnv('_APP_COMPUTE_BUILD_TIMEOUT'),
            '_APP_COMPUTE_SIZE_LIMIT' => +System::getEnv('_APP_COMPUTE_SIZE_LIMIT'),
            '_APP_USAGE_STATS' => System::getEnv('_APP_USAGE_STATS'),
            '_APP_VCS_ENABLED' => $isVcsEnabled,
            '_APP_DOMAIN_ENABLED' => $isDomainEnabled,
            '_APP_ASSISTANT_ENABLED' => $isAssistantEnabled,
            '_APP_DOMAIN_SITES' => $platform['sitesDomain'],
            '_APP_DOMAIN_FUNCTIONS' => $platform['functionsDomain'],
            '_APP_OPTIONS_FORCE_HTTPS' => System::getEnv('_APP_OPTIONS_FORCE_HTTPS'),
            '_APP_DOMAINS_NAMESERVERS' => System::getEnv('_APP_DOMAINS_NAMESERVERS'),
            '_APP_DB_ADAPTER' => System::getEnv('_APP_DB_ADAPTER', 'mariadb'),
            'supportForRelationships' => $adapter->getSupportForRelationships(),
            'supportForOperators' => $adapter->getSupportForOperators(),
            'supportForSpatials' => $adapter->getSupportForSpatialAttributes(),
            'supportForSpatialIndexNull' => $adapter->getSupportForSpatialIndexNull(),
            'supportForFulltextWildcard' => $adapter->getSupportForFulltextWildcardIndex(),
            'maxIndexLength' => $adapter->getMaxIndexLength(),
        ]);

        $response->dynamic($variables, Response::MODEL_CONSOLE_VARIABLES);
    }
}
