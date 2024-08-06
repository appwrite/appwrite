<?php

namespace Appwrite\Utopia\Response;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Account;
use Appwrite\Utopia\Response\Model\AlgoArgon2;
use Appwrite\Utopia\Response\Model\AlgoBcrypt;
use Appwrite\Utopia\Response\Model\AlgoMd5;
use Appwrite\Utopia\Response\Model\AlgoPhpass;
use Appwrite\Utopia\Response\Model\AlgoScrypt;
use Appwrite\Utopia\Response\Model\AlgoScryptModified;
use Appwrite\Utopia\Response\Model\AlgoSha;
use Appwrite\Utopia\Response\Model\Any;
use Appwrite\Utopia\Response\Model\Attribute;
use Appwrite\Utopia\Response\Model\AttributeBoolean;
use Appwrite\Utopia\Response\Model\AttributeDatetime;
use Appwrite\Utopia\Response\Model\AttributeEmail;
use Appwrite\Utopia\Response\Model\AttributeEnum;
use Appwrite\Utopia\Response\Model\AttributeFloat;
use Appwrite\Utopia\Response\Model\AttributeInteger;
use Appwrite\Utopia\Response\Model\AttributeIP;
use Appwrite\Utopia\Response\Model\AttributeList;
use Appwrite\Utopia\Response\Model\AttributeRelationship;
use Appwrite\Utopia\Response\Model\AttributeString;
use Appwrite\Utopia\Response\Model\AttributeURL;
use Appwrite\Utopia\Response\Model\AuthProvider;
use Appwrite\Utopia\Response\Model\BaseList;
use Appwrite\Utopia\Response\Model\Branch;
use Appwrite\Utopia\Response\Model\Bucket;
use Appwrite\Utopia\Response\Model\Build;
use Appwrite\Utopia\Response\Model\Collection;
use Appwrite\Utopia\Response\Model\ConsoleVariables;
use Appwrite\Utopia\Response\Model\Continent;
use Appwrite\Utopia\Response\Model\Country;
use Appwrite\Utopia\Response\Model\Currency;
use Appwrite\Utopia\Response\Model\Database;
use Appwrite\Utopia\Response\Model\Deployment;
use Appwrite\Utopia\Response\Model\Detection;
use Appwrite\Utopia\Response\Model\Document as ModelDocument;
use Appwrite\Utopia\Response\Model\Error;
use Appwrite\Utopia\Response\Model\ErrorDev;
use Appwrite\Utopia\Response\Model\Execution;
use Appwrite\Utopia\Response\Model\File;
use Appwrite\Utopia\Response\Model\Func;
use Appwrite\Utopia\Response\Model\Headers;
use Appwrite\Utopia\Response\Model\HealthAntivirus;
use Appwrite\Utopia\Response\Model\HealthCertificate;
use Appwrite\Utopia\Response\Model\HealthQueue;
use Appwrite\Utopia\Response\Model\HealthStatus;
use Appwrite\Utopia\Response\Model\HealthTime;
use Appwrite\Utopia\Response\Model\HealthVersion;
use Appwrite\Utopia\Response\Model\Identity;
use Appwrite\Utopia\Response\Model\Index;
use Appwrite\Utopia\Response\Model\Installation;
use Appwrite\Utopia\Response\Model\JWT;
use Appwrite\Utopia\Response\Model\Key;
use Appwrite\Utopia\Response\Model\Language;
use Appwrite\Utopia\Response\Model\Locale;
use Appwrite\Utopia\Response\Model\LocaleCode;
use Appwrite\Utopia\Response\Model\Log;
use Appwrite\Utopia\Response\Model\Membership;
use Appwrite\Utopia\Response\Model\Message;
use Appwrite\Utopia\Response\Model\Metric;
use Appwrite\Utopia\Response\Model\MetricBreakdown;
use Appwrite\Utopia\Response\Model\MFAChallenge;
use Appwrite\Utopia\Response\Model\MFAFactors;
use Appwrite\Utopia\Response\Model\MFARecoveryCodes;
use Appwrite\Utopia\Response\Model\MFAType;
use Appwrite\Utopia\Response\Model\Migration;
use Appwrite\Utopia\Response\Model\MigrationFirebaseProject;
use Appwrite\Utopia\Response\Model\MigrationReport;
use Appwrite\Utopia\Response\Model\Mock;
use Appwrite\Utopia\Response\Model\MockNumber;
use Appwrite\Utopia\Response\Model\None;
use Appwrite\Utopia\Response\Model\Phone;
use Appwrite\Utopia\Response\Model\Platform;
use Appwrite\Utopia\Response\Model\Preferences;
use Appwrite\Utopia\Response\Model\Project;
use Appwrite\Utopia\Response\Model\Provider;
use Appwrite\Utopia\Response\Model\ProviderRepository;
use Appwrite\Utopia\Response\Model\Rule;
use Appwrite\Utopia\Response\Model\Runtime;
use Appwrite\Utopia\Response\Model\Session;
use Appwrite\Utopia\Response\Model\Subscriber;
use Appwrite\Utopia\Response\Model\Target;
use Appwrite\Utopia\Response\Model\Team;
use Appwrite\Utopia\Response\Model\TemplateEmail;
use Appwrite\Utopia\Response\Model\TemplateSMS;
use Appwrite\Utopia\Response\Model\Token;
use Appwrite\Utopia\Response\Model\Topic;
use Appwrite\Utopia\Response\Model\UsageBuckets;
use Appwrite\Utopia\Response\Model\UsageCollection;
use Appwrite\Utopia\Response\Model\UsageDatabase;
use Appwrite\Utopia\Response\Model\UsageDatabases;
use Appwrite\Utopia\Response\Model\UsageFunction;
use Appwrite\Utopia\Response\Model\UsageFunctions;
use Appwrite\Utopia\Response\Model\UsageProject;
use Appwrite\Utopia\Response\Model\UsageStorage;
use Appwrite\Utopia\Response\Model\UsageUsers;
use Appwrite\Utopia\Response\Model\User;
use Appwrite\Utopia\Response\Model\Variable;
use Appwrite\Utopia\Response\Model\VcsContent;
use Appwrite\Utopia\Response\Model\Webhook;
use Exception;

class Models
{
    public static function init()
    {
        // General
        self::setModel(new None());
        self::setModel(new Any());
        self::setModel(new Error());
        self::setModel(new ErrorDev());
        // Lists
        self::setModel(new BaseList('Documents List', Response::MODEL_DOCUMENT_LIST, 'documents', Response::MODEL_DOCUMENT));
        self::setModel(new BaseList('Collections List', Response::MODEL_COLLECTION_LIST, 'collections', Response::MODEL_COLLECTION));
        self::setModel(new BaseList('Databases List', Response::MODEL_DATABASE_LIST, 'databases', Response::MODEL_DATABASE));
        self::setModel(new BaseList('Indexes List', Response::MODEL_INDEX_LIST, 'indexes', Response::MODEL_INDEX));
        self::setModel(new BaseList('Users List', Response::MODEL_USER_LIST, 'users', Response::MODEL_USER));
        self::setModel(new BaseList('Sessions List', Response::MODEL_SESSION_LIST, 'sessions', Response::MODEL_SESSION));
        self::setModel(new BaseList('Identities List', Response::MODEL_IDENTITY_LIST, 'identities', Response::MODEL_IDENTITY));
        self::setModel(new BaseList('Logs List', Response::MODEL_LOG_LIST, 'logs', Response::MODEL_LOG));
        self::setModel(new BaseList('Files List', Response::MODEL_FILE_LIST, 'files', Response::MODEL_FILE));
        self::setModel(new BaseList('Buckets List', Response::MODEL_BUCKET_LIST, 'buckets', Response::MODEL_BUCKET));
        self::setModel(new BaseList('Teams List', Response::MODEL_TEAM_LIST, 'teams', Response::MODEL_TEAM));
        self::setModel(new BaseList('Memberships List', Response::MODEL_MEMBERSHIP_LIST, 'memberships', Response::MODEL_MEMBERSHIP));
        self::setModel(new BaseList('Functions List', Response::MODEL_FUNCTION_LIST, 'functions', Response::MODEL_FUNCTION));
        self::setModel(new BaseList('Function Templates List', Response::MODEL_TEMPLATE_FUNCTION_LIST, 'templates', Response::MODEL_TEMPLATE_FUNCTION));
        self::setModel(new BaseList('Installations List', Response::MODEL_INSTALLATION_LIST, 'installations', Response::MODEL_INSTALLATION));
        self::setModel(new BaseList('Provider Repositories List', Response::MODEL_PROVIDER_REPOSITORY_LIST, 'providerRepositories', Response::MODEL_PROVIDER_REPOSITORY));
        self::setModel(new BaseList('Branches List', Response::MODEL_BRANCH_LIST, 'branches', Response::MODEL_BRANCH));
        self::setModel(new BaseList('Runtimes List', Response::MODEL_RUNTIME_LIST, 'runtimes', Response::MODEL_RUNTIME));
        self::setModel(new BaseList('Deployments List', Response::MODEL_DEPLOYMENT_LIST, 'deployments', Response::MODEL_DEPLOYMENT));
        self::setModel(new BaseList('Executions List', Response::MODEL_EXECUTION_LIST, 'executions', Response::MODEL_EXECUTION));
        self::setModel(new BaseList('Builds List', Response::MODEL_BUILD_LIST, 'builds', Response::MODEL_BUILD)); // Not used anywhere yet;
        self::setModel(new BaseList('Projects List', Response::MODEL_PROJECT_LIST, 'projects', Response::MODEL_PROJECT, true, false));
        self::setModel(new BaseList('Webhooks List', Response::MODEL_WEBHOOK_LIST, 'webhooks', Response::MODEL_WEBHOOK, true, false));
        self::setModel(new BaseList('API Keys List', Response::MODEL_KEY_LIST, 'keys', Response::MODEL_KEY, true, false));
        self::setModel(new BaseList('Auth Providers List', Response::MODEL_AUTH_PROVIDER_LIST, 'platforms', Response::MODEL_AUTH_PROVIDER, true, false));
        self::setModel(new BaseList('Platforms List', Response::MODEL_PLATFORM_LIST, 'platforms', Response::MODEL_PLATFORM, true, false));
        self::setModel(new BaseList('Countries List', Response::MODEL_COUNTRY_LIST, 'countries', Response::MODEL_COUNTRY));
        self::setModel(new BaseList('Continents List', Response::MODEL_CONTINENT_LIST, 'continents', Response::MODEL_CONTINENT));
        self::setModel(new BaseList('Languages List', Response::MODEL_LANGUAGE_LIST, 'languages', Response::MODEL_LANGUAGE));
        self::setModel(new BaseList('Currencies List', Response::MODEL_CURRENCY_LIST, 'currencies', Response::MODEL_CURRENCY));
        self::setModel(new BaseList('Phones List', Response::MODEL_PHONE_LIST, 'phones', Response::MODEL_PHONE));
        self::setModel(new BaseList('Metric List', Response::MODEL_METRIC_LIST, 'metrics', Response::MODEL_METRIC, true, false));
        self::setModel(new BaseList('Variables List', Response::MODEL_VARIABLE_LIST, 'variables', Response::MODEL_VARIABLE));
        self::setModel(new BaseList('Status List', Response::MODEL_HEALTH_STATUS_LIST, 'statuses', Response::MODEL_HEALTH_STATUS));
        self::setModel(new BaseList('Rule List', Response::MODEL_PROXY_RULE_LIST, 'rules', Response::MODEL_PROXY_RULE));
        self::setModel(new BaseList('Locale codes list', Response::MODEL_LOCALE_CODE_LIST, 'localeCodes', Response::MODEL_LOCALE_CODE));
        self::setModel(new BaseList('Provider list', Response::MODEL_PROVIDER_LIST, 'providers', Response::MODEL_PROVIDER));
        self::setModel(new BaseList('Message list', Response::MODEL_MESSAGE_LIST, 'messages', Response::MODEL_MESSAGE));
        self::setModel(new BaseList('Topic list', Response::MODEL_TOPIC_LIST, 'topics', Response::MODEL_TOPIC));
        self::setModel(new BaseList('Subscriber list', Response::MODEL_SUBSCRIBER_LIST, 'subscribers', Response::MODEL_SUBSCRIBER));
        self::setModel(new BaseList('Target list', Response::MODEL_TARGET_LIST, 'targets', Response::MODEL_TARGET));
        self::setModel(new BaseList('Migrations List', Response::MODEL_MIGRATION_LIST, 'migrations', Response::MODEL_MIGRATION));
        self::setModel(new BaseList('Migrations Firebase Projects List', Response::MODEL_MIGRATION_FIREBASE_PROJECT_LIST, 'projects', Response::MODEL_MIGRATION_FIREBASE_PROJECT));
        self::setModel(new BaseList('VCS Content List', Response::MODEL_VCS_CONTENT_LIST, 'contents', Response::MODEL_VCS_CONTENT));
        // Entities
        self::setModel(new Database());
        self::setModel(new Collection());
        self::setModel(new Attribute());
        self::setModel(new AttributeList());
        self::setModel(new AttributeString());
        self::setModel(new AttributeInteger());
        self::setModel(new AttributeFloat());
        self::setModel(new AttributeBoolean());
        self::setModel(new AttributeEmail());
        self::setModel(new AttributeEnum());
        self::setModel(new AttributeIP());
        self::setModel(new AttributeURL());
        self::setModel(new AttributeDatetime());
        self::setModel(new AttributeRelationship());
        self::setModel(new Index());
        self::setModel(new ModelDocument());
        self::setModel(new Log());
        self::setModel(new User());
        self::setModel(new AlgoMd5());
        self::setModel(new AlgoSha());
        self::setModel(new AlgoPhpass());
        self::setModel(new AlgoBcrypt());
        self::setModel(new AlgoScrypt());
        self::setModel(new AlgoScryptModified());
        self::setModel(new AlgoArgon2());
        self::setModel(new Account());
        self::setModel(new Preferences());
        self::setModel(new Session());
        self::setModel(new Identity());
        self::setModel(new Token());
        self::setModel(new JWT());
        self::setModel(new Locale());
        self::setModel(new LocaleCode());
        self::setModel(new File());
        self::setModel(new Bucket());
        self::setModel(new Team());
        self::setModel(new Membership());
        self::setModel(new Func());
        self::setModel(new Installation());
        self::setModel(new ProviderRepository());
        self::setModel(new Detection());
        self::setModel(new VcsContent());
        self::setModel(new Branch());
        self::setModel(new Runtime());
        self::setModel(new Deployment());
        self::setModel(new Execution());
        self::setModel(new Build());
        self::setModel(new Project());
        self::setModel(new Webhook());
        self::setModel(new Key());
        self::setModel(new MockNumber());
        self::setModel(new AuthProvider());
        self::setModel(new Platform());
        self::setModel(new Variable());
        self::setModel(new Country());
        self::setModel(new Continent());
        self::setModel(new Language());
        self::setModel(new Currency());
        self::setModel(new Phone());
        self::setModel(new HealthAntivirus());
        self::setModel(new HealthQueue());
        self::setModel(new HealthStatus());
        self::setModel(new HealthCertificate());
        self::setModel(new HealthTime());
        self::setModel(new HealthVersion());
        self::setModel(new Metric());
        self::setModel(new MetricBreakdown());
        self::setModel(new UsageDatabases());
        self::setModel(new UsageDatabase());
        self::setModel(new UsageCollection());
        self::setModel(new UsageUsers());
        self::setModel(new UsageStorage());
        self::setModel(new UsageBuckets());
        self::setModel(new UsageFunctions());
        self::setModel(new UsageFunction());
        self::setModel(new UsageProject());
        self::setModel(new Headers());
        self::setModel(new Rule());
        self::setModel(new TemplateSMS());
        self::setModel(new TemplateEmail());
        self::setModel(new ConsoleVariables());
        self::setModel(new MFAChallenge());
        self::setModel(new MFARecoveryCodes());
        self::setModel(new MFAType());
        self::setModel(new MFAFactors());
        self::setModel(new Provider());
        self::setModel(new Message());
        self::setModel(new Topic());
        self::setModel(new Subscriber());
        self::setModel(new Target());
        self::setModel(new Migration());
        self::setModel(new MigrationReport());
        self::setModel(new MigrationFirebaseProject());
        // Tests (keep last)
        self::setModel(new Mock());
        ;
    }
    /**
     *  List of defined output objects
     * @var Model[]
     */
    protected static array $models = [];

    /**
     * Set Model Object
     */
    public static function setModel(Model $instance): void
    {
        self::$models[$instance->getType()] = $instance;
    }

    /**
     * Get Model Object
     *
     * @param string $key
     * @return Model
     * @throws Exception
     */
    public static function getModel(string $key): Model
    {
        if (!isset(self::$models[$key])) {
            throw new Exception('Undefined model: ' . $key);
        }

        return self::$models[$key];
    }

    public static function getModels(): array
    {
        return self::$models;
    }
}
