<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\Projects\Credentials;

use Appwrite\Auth\OAuth2\Supabase as OAuth2Supabase;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Client\Client;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Validator\Text;

class Create extends Base
{
    /**
     * How long the temporary role can sign in, which bounds a migration run
     */
    private const int EXPIRY = 86400;

    public static function getName(): string
    {
        return 'createSupabaseProjectCredentials';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/migrations/supabase/projects/:projectRef/credentials')
            ->desc('Create Supabase project credentials')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'createSupabaseProjectCredentials',
                description: <<<EOT
                Create temporary credentials to migrate a project of the connected Supabase account. The project's database gets a read only login role that expires after 24 hours. Pass the credentials to the Supabase report and migration endpoints.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_MIGRATION_SUPABASE_CREDENTIALS,
                    )
                ]
            ))
            ->param('projectRef', '', new Text(20, 20, \range('a', 'z')), 'Supabase project reference.')
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForPlatform')
            ->inject('clientForOAuth2')
            ->callback($this->action(...));
    }

    public function action(
        string $projectRef,
        Request $request,
        Response $response,
        User $user,
        Database $dbForPlatform,
        Client $clientForOAuth2,
    ): void {
        $oauth2 = $this->oauth2($clientForOAuth2, $request);
        $accessToken = $this->getAccessToken($oauth2, $dbForPlatform, $this->getIdentity($dbForPlatform, $user));

        try {
            $apiKey = $oauth2->getSecretKey($accessToken, $projectRef);
            $pooler = $oauth2->getPooler($accessToken, $projectRef);
        } catch (\Throwable) {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Unable to read the Supabase project. Check that your Supabase account can manage it.');
        }

        $databaseHost = $pooler['db_host'] ?? '';

        if ($apiKey === '' || $databaseHost === '') {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'The Supabase project has no secret key or database pooler.');
        }

        $this->dropExpiredRoles($oauth2, $accessToken, $projectRef);

        $role = OAuth2Supabase::ROLE_PREFIX . \bin2hex(\random_bytes(8));
        $password = \bin2hex(\random_bytes(24));
        $expire = DateTime::addSeconds(new \DateTime(), self::EXPIRY);

        // The password goes over as a SCRAM verifier, so it never appears in the
        // project's statement logs
        $create = 'CREATE ROLE ' . $role . ' LOGIN PASSWORD \'' . $this->scram($password) . '\' VALID UNTIL \'' . $expire . '+00\' IN ROLE pg_read_all_data';

        try {
            // Storage objects sit behind row level security, which only a bypassing role can read
            $oauth2->query($accessToken, $projectRef, $create . ' BYPASSRLS');
        } catch (\Throwable) {
            try {
                $oauth2->query($accessToken, $projectRef, $create);
            } catch (\Throwable) {
                throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Unable to create a database role on the Supabase project.');
            }
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document([
                'endpoint' => 'https://' . $projectRef . '.supabase.co',
                'apiKey' => $apiKey,
                'databaseHost' => $databaseHost,
                // Session mode, since transaction mode breaks prepared statements; the pooler
                // finds the project from the suffix on the user
                'port' => 5432,
                'username' => $role . '.' . $projectRef,
                'password' => $password,
                'expire' => $expire,
            ]), Response::MODEL_MIGRATION_SUPABASE_CREDENTIALS);
    }

    /**
     * Drop roles earlier credentials left behind once they can no longer sign in
     */
    private function dropExpiredRoles(OAuth2Supabase $oauth2, string $accessToken, string $projectRef): void
    {
        try {
            $roles = $oauth2->query($accessToken, $projectRef, 'SELECT rolname FROM pg_roles WHERE starts_with(rolname, \'' . OAuth2Supabase::ROLE_PREFIX . '\') AND rolvaliduntil < now()');

            foreach ($roles as $row) {
                $name = $row['rolname'] ?? '';
                $suffix = \substr($name, \strlen(OAuth2Supabase::ROLE_PREFIX));

                // Only names this endpoint generates, so nothing else reaches the statement
                if (\strlen($suffix) !== 16 || !\ctype_xdigit($suffix)) {
                    continue;
                }

                $oauth2->query($accessToken, $projectRef, 'DROP ROLE IF EXISTS ' . $name);
            }
        } catch (\Throwable) {
            // An expired role cannot sign in, so cleanup can wait for the next credentials
        }
    }

    /**
     * SCRAM-SHA-256 verifier in the form Postgres stores, per RFC 5802 and RFC 7677
     */
    private function scram(string $password): string
    {
        $iterations = 4096;
        $salt = \random_bytes(16);
        $salted = \hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        $storedKey = \hash('sha256', \hash_hmac('sha256', 'Client Key', $salted, true), true);
        $serverKey = \hash_hmac('sha256', 'Server Key', $salted, true);

        return 'SCRAM-SHA-256$' . $iterations . ':' . \base64_encode($salt) . '$' . \base64_encode($storedKey) . ':' . \base64_encode($serverKey);
    }
}
