<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\LDAP\Tests;

use Appwrite\Auth\LDAP\Client;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createProjectLDAPTest';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/project/ldap/tests')
            ->httpAlias('/v1/projects/:projectId/ldap/tests')
            ->desc('Create project LDAP test')
            ->groups(['api', 'project'])
            ->label('scope', 'project.ldap.write')
            ->label('audits.event', 'project.ldap.test')
            ->label('audits.resource', 'project/{project.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'ldap',
                name: 'createLDAPTest',
                description: <<<EOT
                Test the stored LDAP configuration against the directory without signing anyone in. Connects to the directory, binds as the service account and reads the base DN. Pass a username to also look the user up and check the required group, and a password to also verify it. The error names the step that failed.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
            ))
            ->param('username', '', new Text(256, 0), 'Username to look up in the directory. Leave empty to test the connection and service account only.', optional: true)
            ->param('password', '', new PasswordFormat(new Text(256, 0)), 'Password of the user to verify. Only used together with a username.', optional: true)
            ->inject('response')
            ->inject('project')
            ->inject('publicHostname')
            ->callback($this->action(...));
    }

    public function action(
        string $username,
        string $password,
        Response $response,
        Document $project,
        PublicHostname $publicHostname
    ): void {
        $ldap = $project->getAttribute('ldap', []);

        foreach (['hosts', 'baseDn'] as $name) {
            if (empty($ldap[$name])) {
                throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, 'Param "' . $name . '" must be configured on the project to test LDAP.');
            }
        }

        (new Client($publicHostname, $ldap))->test($username ?: null, $password ?: null);

        $response->noContent();
    }
}
