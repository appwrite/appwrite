<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities\Ios;

use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Provisioning;
use Appwrite\Distribution\Schedules;
use Appwrite\Distribution\Signing\Mode;
use Appwrite\Distribution\Signing\Status;
use Appwrite\Distribution\Stores;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Id;
use Utopia\Distribution\Exception as StoreException;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Signing\Certificate;
use Utopia\Distribution\Signing\Profile;
use Utopia\Distribution\Store;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    private const int LIMIT = 500_000;

    private const int BASE64_BLOCK_BYTES = 3;

    private const int BASE64_BLOCK_CHARACTERS = 4;

    private const int LOCK_TTL = 120;

    private const float LOCK_TIMEOUT = 5.0;

    private const string RETRY_AFTER = '5';

    public static function getName(): string
    {
        return 'createIosSigningIdentity';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/signing-identities/ios')
            ->desc('Create iOS signing identity')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.credentials.write')
            ->label('event', 'distributionSigningIdentities.[signingIdentityId].create')
            ->label('audits.event', 'distribution.signingIdentity.create')
            ->label('audits.resource', 'distributionSigningIdentity/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'signingIdentities',
                name: 'createIosSigningIdentity',
                description: <<<EOT
                Create an iOS signing identity, imported from your files or managed through an App Store Connect connection.

                To import one, pass a distribution certificate (.p12) and its App Store provisioning profiles (.mobileprovision). The certificate and every profile are validated: each profile must be an App Store profile that contains the certificate, belongs to the certificate's team and has not expired.

                To have one managed, pass the ID of an Apple connection and the bundle IDs to sign instead of files. Appwrite creates any bundle ID the team does not have yet, issues one distribution certificate for the team, shared by every managed identity of that team, and creates an App Store profile per bundle ID, which it renews before it expires. Capabilities that need a binding, such as App Groups, iCloud containers or Apple Pay merchant IDs, are not enabled for you: configure them on the bundle ID in your Apple Developer account.

                Files are stored encrypted and never returned except through the download endpoint.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('signingIdentityId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), "Signing identity ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars.", false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Signing identity name. Max length: 128 chars.')
            ->param('certificate', '', new Text(0), 'Base64-encoded distribution certificate with its private key, as a PKCS#12 (.p12) file. Required to import an identity; leave empty for a managed one.', true)
            ->param('password', '', new Text(0, 0), 'Password of the PKCS#12 file. Leave empty for a managed identity.', true)
            ->param('profiles', [], new ArrayList(new Text(0), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Base64-encoded App Store provisioning profiles (.mobileprovision), one per bundle ID. Required to import an identity; leave empty for a managed one. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' profiles are allowed.', true)
            ->param('connectionId', '', new Text(36, 0), 'ID of the Apple connection that manages the identity. Leave empty to import one from files.', true)
            ->param('identifiers', [], new ArrayList(new Text(256), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Bundle IDs the managed identity signs, one App Store profile each. Required with connectionId. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' bundle IDs are allowed.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('distributionStores')
            ->inject('locks')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $profiles
     * @param array<string> $identifiers
     */
    public function action(
        string $signingIdentityId,
        string $name,
        #[\SensitiveParameter]
        string $certificate,
        #[\SensitiveParameter]
        string $password,
        #[\SensitiveParameter]
        array $profiles,
        string $connectionId,
        array $identifiers,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Event $queueForEvents,
        Stores $distributionStores,
        callable $locks,
    ): void {
        $signingIdentityId = $signingIdentityId === 'unique()' ? Id::unique() : $signingIdentityId;

        if (!$dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId)->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_ALREADY_EXISTS);
        }

        $signingIdentity = $connectionId === ''
            ? $this->import($dbForProject, $signingIdentityId, $name, $certificate, $password, $profiles, $identifiers)
            : $this->provision($dbForProject, $distributionStores, $locks, $response, $signingIdentityId, $name, $certificate, $password, $profiles, $connectionId, $identifiers);

        $interval = Provisioning::interval($signingIdentity, new \DateTimeImmutable());

        if ($interval > 0) {
            (new Schedules($dbForPlatform))->write($project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SIGNING_IDENTITY, $signingIdentity, $interval);
        }

        $queueForEvents->setParam('signingIdentityId', $signingIdentity->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($signingIdentity, Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY);
    }

    /**
     * @param array<string> $profiles
     * @param array<string> $identifiers
     */
    private function import(
        Database $dbForProject,
        string $signingIdentityId,
        string $name,
        #[\SensitiveParameter]
        string $certificate,
        #[\SensitiveParameter]
        string $password,
        #[\SensitiveParameter]
        array $profiles,
        array $identifiers,
    ): Document {
        if ($certificate === '') {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Param "certificate" is required to import a signing identity. Pass connectionId and identifiers instead for a managed one.');
        }

        if ($identifiers !== []) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Param "identifiers" needs a connectionId. An imported signing identity takes its bundle IDs from its profiles.');
        }

        if (\strlen($certificate) + \array_sum(\array_map(\strlen(...), $profiles)) > $this->encodedLimit()) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'Signing files must not exceed ' . self::LIMIT . ' bytes in total once decoded.');
        }

        $archive = $this->decode($certificate, 'certificate');
        $envelopes = \array_map(fn (string $profile): string => $this->decode($profile, 'profiles'), $profiles);

        if ($envelopes === []) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'At least one provisioning profile is required.');
        }

        if (\strlen($archive) + \array_sum(\array_map(\strlen(...), $envelopes)) > self::LIMIT) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'Signing files must not exceed ' . self::LIMIT . ' bytes in total once decoded.');
        }

        try {
            $identity = Certificate::fromPkcs12($archive, $password);
        } catch (Invalid $error) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, $error->getMessage());
        }

        $now = new \DateTimeImmutable();
        if ($identity->expiresAt() <= $now) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'The certificate has expired.');
        }

        $team = $identity->team();
        $expiresAt = $identity->expiresAt();
        $identifiers = [];
        $stored = [];

        foreach ($envelopes as $envelope) {
            try {
                $profile = Profile::parse($envelope);
            } catch (Invalid $error) {
                throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, $error->getMessage());
            }

            $team ??= $profile->team;

            $problem = match (true) {
                $profile->type !== Profile::TYPE_APP_STORE => "is a {$profile->type} profile, but App Store distribution needs an app-store profile.",
                !$profile->contains($identity) => 'does not contain the certificate.',
                $profile->team !== $team => "belongs to team {$profile->team}, not {$team}.",
                $profile->expiresAt <= $now => 'has expired.',
                \in_array($profile->identifier, $identifiers, true) => "repeats the bundle ID {$profile->identifier}.",
                default => null,
            };

            if ($problem !== null) {
                throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, "Profile \"{$profile->name}\" {$problem}");
            }

            $identifiers[] = $profile->identifier;
            $stored[$profile->identifier] = \base64_encode($envelope);
            $expiresAt = \min($expiresAt, $profile->expiresAt);
        }

        try {
            return $dbForProject->createDocument('distributionSigningIdentities', new Document([
                '$id' => $signingIdentityId,
                '$permissions' => [],
                'platform' => Platform::Ios->value,
                'name' => $name,
                'mode' => Mode::Imported->value,
                'secret' => [
                    'certificate' => \base64_encode($archive),
                    'password' => $password,
                    'profiles' => $stored,
                ],
                'fingerprint' => $identity->fingerprint(),
                'teamId' => $team,
                'identifiers' => $identifiers,
                'connectionId' => '',
                'connectionInternalId' => '',
                'externalId' => '',
                'expiresAt' => DateTime::format(\DateTime::createFromImmutable($expiresAt)),
                'status' => Status::Active->value,
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_ALREADY_EXISTS);
        } catch (Structure $error) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, $error->getMessage());
        }
    }

    /**
     * @param array<string> $profiles
     * @param array<string> $identifiers
     */
    private function provision(
        Database $dbForProject,
        Stores $distributionStores,
        callable $locks,
        Response $response,
        string $signingIdentityId,
        string $name,
        #[\SensitiveParameter]
        string $certificate,
        #[\SensitiveParameter]
        string $password,
        #[\SensitiveParameter]
        array $profiles,
        string $connectionId,
        array $identifiers,
    ): Document {
        if ($certificate !== '' || $password !== '' || $profiles !== []) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'Leave certificate, password and profiles empty for a managed signing identity; they are issued through the connection.');
        }

        $identifiers = \array_values($identifiers);

        if ($identifiers === []) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Param "identifiers" needs at least one bundle ID for a managed signing identity.');
        }

        if (\count(\array_unique($identifiers)) !== \count($identifiers)) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'Param "identifiers" must not repeat a bundle ID.');
        }

        $connection = $dbForProject->getDocument('distributionConnections', $connectionId);

        if ($connection->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_NOT_FOUND);
        }

        $store = $distributionStores->connection($connection);

        if (!$store instanceof Store\Apple) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, 'A managed iOS signing identity needs an Apple connection.');
        }

        $provisioning = new Provisioning($dbForProject);

        try {
            $teamId = $provisioning->team($store, $identifiers[0], $name)->seed;

            if ($teamId === '') {
                throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, "App Store Connect returned bundle ID \"{$identifiers[0]}\" without a team.");
            }

            return $locks(
                Provisioning::lock($teamId),
                self::LOCK_TTL,
                fn (Distributed $lock): Document => $provisioning->create($store, $lock, $connection, $signingIdentityId, $name, $identifiers, $teamId),
                timeout: self::LOCK_TIMEOUT,
            );
        } catch (Contention) {
            $response->addHeader('Retry-After', self::RETRY_AFTER);
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Another signing change for this Apple team is in progress. Try again.');
        } catch (StoreException $error) {
            throw Provisioning::failure($error);
        } catch (Duplicate) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_ALREADY_EXISTS);
        } catch (Structure $error) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, $error->getMessage());
        }
    }

    private function decode(string $value, string $param): string
    {
        $decoded = \base64_decode($value, true);
        if ($decoded === false || $decoded === '') {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, "Param \"{$param}\" must be base64-encoded.");
        }

        return $decoded;
    }

    private function encodedLimit(): int
    {
        return self::BASE64_BLOCK_CHARACTERS * \intdiv(self::LIMIT + self::BASE64_BLOCK_BYTES - 1, self::BASE64_BLOCK_BYTES);
    }
}
