<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities\Ios;

use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Signing\Mode;
use Appwrite\Distribution\Signing\Status;
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
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Signing\Certificate;
use Utopia\Distribution\Signing\Profile;
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
                Import an iOS signing identity from a distribution certificate (.p12) and its App Store provisioning profiles (.mobileprovision). The certificate and every profile are validated: each profile must be an App Store profile that contains the certificate, belongs to the certificate's team and has not expired. Files are stored encrypted and never returned except through the download endpoint.
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
            ->param('certificate', '', new Text(0), 'Base64-encoded distribution certificate with its private key, as a PKCS#12 (.p12) file.')
            ->param('password', '', new Text(0, 0), 'Password of the PKCS#12 file.')
            ->param('profiles', [], new ArrayList(new Text(0), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Base64-encoded App Store provisioning profiles (.mobileprovision), one per bundle ID. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' profiles are allowed.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $profiles
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
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
    ): void {
        $signingIdentityId = $signingIdentityId === 'unique()' ? Id::unique() : $signingIdentityId;

        if (!$dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId)->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_ALREADY_EXISTS);
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
            $signingIdentity = $dbForProject->createDocument('distributionSigningIdentities', new Document([
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

        $queueForEvents->setParam('signingIdentityId', $signingIdentity->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($signingIdentity, Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY);
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
