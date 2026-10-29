<?php

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Auth\Key;
use Appwrite\Auth\Validator\PasswordPwned\Appwrite as PasswordPwnedAppwrite;
use Appwrite\Auth\Validator\PasswordPwned\HIBP as PasswordPwnedHIBP;
use Appwrite\Auth\Validator\PasswordPwned\Mock as PasswordPwnedMock;
use Appwrite\Auth\Validator\PasswordPwned\None as PasswordPwnedNone;
use Appwrite\Database\Factory as DatabaseFactory;
use Appwrite\Databases\TransactionState;
use Appwrite\Deployment\Deployments;
use Appwrite\Event\Context\Audit as AuditContext;
use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
use Appwrite\Functions\EventProcessor;
use Appwrite\Geo\Client as GeoClient;
use Appwrite\Geo\Geo;
use Appwrite\GraphQL\ResolverLock;
use Appwrite\GraphQL\Schema;
use Appwrite\Locking\Lock;
use Appwrite\Network\Cors;
use Appwrite\Network\Platform;
use Appwrite\Network\Validator\Origin;
use Appwrite\Network\Validator\Redirect;
use Appwrite\Usage\Connection as UsageConnection;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Usage\Operations;
use Appwrite\Usage\Policy as UsagePolicy;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Hook\FunctionCache;
use Appwrite\Utopia\Database\Hook\Joins;
use Appwrite\Utopia\Database\Hook\Metadata;
use Appwrite\Utopia\Database\Hook\Usage;
use Appwrite\Utopia\Database\Hook\UserEvents;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use GraphQL\Error\Error as GraphQLError;
use OpenRuntimes\Orchestrator\Jobs;
use Swoole\Table;
use Utopia\Agents\Adapters\Appwrite as AppwriteAdapter;
use Utopia\Agents\Agent;
use Utopia\Audit\Adapter\Database as AdapterDatabase;
use Utopia\Audit\Audit;
use Utopia\Auth\Hashes\Argon2;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Code;
use Utopia\Auth\Proofs\Password;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\Domains\Domain;
use Utopia\DSN\DSN;
use Utopia\Http\Http;
use Utopia\Locale\Locale;
use Utopia\Lock\Distributed as DistributedLock;
use Utopia\Pools\Group;
use Utopia\Query\Method;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;
use Utopia\Registry\Registry;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Usage\Tenant as UsageTenant;

/**
 * Register per-request resources on the given container.
 * These resources depend (directly or transitively) on request/response
 * and must be fresh for each HTTP request.
 */
return function (Container $context): void {
    $context->set('utopia:graphql', fn ($utopia) => $utopia, ['utopia']);

    $context->set('resolverLock', fn (): ResolverLock => new ResolverLock(), []);

    $context->set('lock', function (Group $pools, Telemetry $telemetry, Document $project): Lock {
        return new Lock(
            fn (string $key, int $ttl, Closure $callback): mixed => $pools->get('lock')->use(
                fn (\Redis $redis): mixed => $callback(new DistributedLock($redis, $key, $ttl))
            ),
            $telemetry,
            $project
        );
    }, ['pools', 'telemetry', 'project']);

    $context->set('authorization', fn () => new Authorization(), []);

    $context->set('store', fn (): Store => new Store(), []);

    $context->set('proofForPassword', function (): Password {
        $hash = new Argon2();
        $hash
            ->setMemoryCost(7168)
            ->setTimeCost(5)
            ->setThreads(1);

        $password = new Password();
        $password
            ->setHash($hash);

        return $password;
    });

    $context->set('proofForToken', function (): Token {
        $token = new Token();
        $token->setHash(new Sha());

        return $token;
    });

    $context->set('proofForCode', function (): Code {
        $code = new Code();
        $code->setHash(new Sha());

        return $code;
    });

    $context->set('locale', function () {
        $locale = new Locale(System::getEnv('_APP_LOCALE', 'en'));
        // Always fall back to English — it is the only complete translation set.
        // Using _APP_LOCALE here left missing keys as {{emails.*}} when the
        // instance default was a partial locale (e.g. fr). See #12448.
        $locale->setFallback('en');

        return $locale;
    });

    // Per-request queue resources (stateful, accumulate event data during request)
    $context->set('queueForEvents', fn (Publisher $publisher) => new Event($publisher), ['publisher']);
    $context->set('queueForWebhooks', fn (Publisher $publisher) => new Webho