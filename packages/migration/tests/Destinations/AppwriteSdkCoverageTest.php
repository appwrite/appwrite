<?php

namespace Utopia\Migration\Tests\Destinations;

use Appwrite\Service;
use Appwrite\Services\Project;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Resources\Auth\OAuth2\OAuth2Provider;

/**
 * Fails when the appwrite/appwrite SDK can write something the Appwrite destination does not
 * migrate, so an SDK bump forces a decision instead of silently dropping the new field.
 *
 * Each entry below records why a parameter or setting is left out. Migrate it, or add it here.
 */
class AppwriteSdkCoverageTest extends TestCase
{
    private const array VCS = [
        'installationId',
        'providerRepositoryId',
        'providerBranch',
        'providerSilentMode',
        'providerRootDirectory',
        'providerBranches',
        'providerPaths',
    ];

    /**
     * SDK parameters the destination deliberately does not send, by "Service::method".
     *
     * @var array<string, array<string, string>>
     */
    private const array SKIPPED_PARAMETERS = [
        'Teams::create' => [
            'roles' => 'The destination creates teams with an API key, so there is no creator to give roles to.',
        ],
        'Teams::createMembership' => [
            'phone' => 'Members are added by userId; phone, url and name only address an invite.',
            'url' => 'Members are added by userId; phone, url and name only address an invite.',
            'name' => 'Members are added by userId; phone, url and name only address an invite.',
        ],
        'Storage::createFile' => [
            'permissions' => 'Only a permission probe calls createFile; uploads are chunked requests in importFile.',
            'folder' => 'Only a permission probe calls createFile; uploads are chunked requests in importFile.',
            'onProgress' => 'Only a permission probe calls createFile; uploads are chunked requests in importFile.',
        ],
    ];

    /**
     * Services whose create methods take the VCS connection, which belongs to the source organization's Git installation.
     */
    private const array VCS_SERVICES = ['Functions::create', 'Sites::create'];

    /**
     * Project settings the destination writes straight to the platform database, so the
     * parameter check cannot see them. Policies and OAuth2 providers are checked separately.
     *
     * @var array<string, string>
     */
    private const array PROJECT_METHODS = [
        'updateAuthMethod' => 'migrated: AuthMethods',
        'updateLabels' => 'migrated: Labels',
        'updateProtocol' => 'migrated: Protocols',
        'updateService' => 'migrated: Services',
        'updateSMTP' => 'migrated: SMTP',
        'updateEmailTemplate' => 'migrated: EmailTemplate',
        'createVariable' => 'migrated: ProjectVariable',
        'updateVariable' => 'migrated: ProjectVariable',
        'updateKey' => 'migrated: ApiKey',
        'createAndroidPlatform' => 'migrated: Platform',
        'updateAndroidPlatform' => 'migrated: Platform',
        'createApplePlatform' => 'migrated: Platform',
        'updateApplePlatform' => 'migrated: Platform',
        'createLinuxPlatform' => 'migrated: Platform',
        'updateLinuxPlatform' => 'migrated: Platform',
        'createWebPlatform' => 'migrated: Platform',
        'updateWebPlatform' => 'migrated: Platform',
        'createWindowsPlatform' => 'migrated: Platform',
        'updateWindowsPlatform' => 'migrated: Platform',
        'createEphemeralKey' => 'skipped: ephemeral keys expire within minutes and are never listed.',
        'createSMTPTest' => 'skipped: sends a test email; there is nothing to migrate.',
        'createMockPhone' => 'not migrated yet: mock phone numbers for testing phone sign-in.',
        'updateMockPhone' => 'not migrated yet: mock phone numbers for testing phone sign-in.',
        'updateOAuth2Server' => 'not migrated yet: the project acting as an OAuth2 server, and its clients.',
    ];

    /**
     * Policy update methods whose policy Policies carries.
     */
    private const array POLICIES = [
        'updateDenyAliasedEmailPolicy',
        'updateDenyCorporateEmailPolicy',
        'updateDenyDisposableEmailPolicy',
        'updateDenyFreeEmailPolicy',
        'updateMembershipPrivacyPolicy',
        'updateMFAFactorsPolicy',
        'updatePasswordDictionaryPolicy',
        'updatePasswordHistoryPolicy',
        'updatePasswordPersonalDataPolicy',
        'updatePasswordStrengthPolicy',
        'updateSessionAlertPolicy',
        'updateSessionDurationPolicy',
        'updateSessionInvalidationPolicy',
        'updateSessionLimitPolicy',
        'updateUserLimitPolicy',
    ];

    /**
     * Provider ids that differ from the SDK method suffix.
     */
    private const array PROVIDER_ALIASES = ['tradeshiftsandbox' => 'tradeshiftbox'];

    public function testDestinationSendsEverySdkParameter(): void
    {
        $missing = [];

        foreach ($this->collectDestinationCalls() as $key => $passed) {
            [$class, $method] = \explode('::', $key);
            $name = (new ReflectionClass($class))->getShortName() . '::' . $method;
            $skipped = \array_keys(self::SKIPPED_PARAMETERS[$name] ?? []);
            if (\in_array($name, self::VCS_SERVICES, true)) {
                \array_push($skipped, ...self::VCS);
            }

            foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                if (!isset($passed[$parameter->getName()]) && !\in_array($parameter->getName(), $skipped, true)) {
                    $missing[] = $name . '(' . $parameter->getName() . ')';
                }
            }
        }

        $this->assertSame([], $missing, 'The destination does not send these SDK parameters. Migrate them, or record why in SKIPPED_PARAMETERS.');
    }

    public function testEveryProjectSettingIsMigratedOrSkipped(): void
    {
        $providers = \array_map(\strtolower(...), \array_keys(OAuth2Provider::PROVIDERS));
        $unknown = [];

        foreach ((new ReflectionClass(Project::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (!\str_starts_with($name, 'create') && !\str_starts_with($name, 'update')) {
                continue;
            }

            if (\str_starts_with($name, 'updateOAuth2') && $name !== 'updateOAuth2Server') {
                $provider = \strtolower(\substr($name, \strlen('updateOAuth2')));
                if (!\in_array(self::PROVIDER_ALIASES[$provider] ?? $provider, $providers, true)) {
                    $unknown[] = $name;
                }
                continue;
            }

            if (\str_ends_with($name, 'Policy')) {
                if (!\in_array($name, self::POLICIES, true)) {
                    $unknown[] = $name;
                }
                continue;
            }

            if (!isset(self::PROJECT_METHODS[$name])) {
                $unknown[] = $name;
            }
        }

        $this->assertSame([], $unknown, 'The SDK can write these project settings, which migration neither carries nor records as skipped.');
    }

    /**
     * Named and positional arguments of every create and update call the destination makes
     * on an SDK service, merged per method across call sites.
     *
     * @return array<string, array<string, true>>
     */
    private function collectDestinationCalls(): array
    {
        $class = new ReflectionClass(AppwriteDestination::class);
        $source = (string) \file_get_contents((string) $class->getFileName());

        $services = [];
        foreach ($class->getProperties() as $property) {
            $type = $property->getType();
            if ($type instanceof ReflectionNamedType && \is_subclass_of($type->getName(), Service::class)) {
                $services[$property->getName()] = $type->getName();
            }
        }

        \preg_match_all('/\$this->(\w+)->((?:create|update)\w*)\(/', $source, $matches, PREG_OFFSET_CAPTURE);

        $calls = [];
        foreach ($matches[0] as $index => [$call, $offset]) {
            $service = $services[$matches[1][$index][0]] ?? null;
            if ($service === null) {
                continue;
            }

            $method = $matches[2][$index][0];
            $parameters = \array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                (new ReflectionMethod($service, $method))->getParameters(),
            );
            $key = $service . '::' . $method;
            $calls[$key] ??= [];

            foreach ($this->splitArguments($source, $offset + \strlen($call)) as $position => $argument) {
                if (\preg_match('/^(\w+)\s*:(?!:)/', $argument, $named)) {
                    $calls[$key][$named[1]] = true;
                } elseif (isset($parameters[$position])) {
                    $calls[$key][$parameters[$position]] = true;
                }
            }
        }

        return $calls;
    }

    /**
     * @return list<string> the top-level arguments of the call whose opening parenthesis ends at $offset
     */
    private function splitArguments(string $source, int $offset): array
    {
        $arguments = [];
        $current = '';
        $depth = 1;

        for ($position = $offset; $depth > 0; $position++) {
            $character = $source[$position];

            if (\in_array($character, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (\in_array($character, [')', ']', '}'], true)) {
                $depth--;
            }

            if ($depth === 1 && $character === ',') {
                $arguments[] = \trim($current);
                $current = '';
            } elseif ($depth > 0) {
                $current .= $character;
            }
        }

        if (\trim($current) !== '') {
            $arguments[] = \trim($current);
        }

        return $arguments;
    }
}
