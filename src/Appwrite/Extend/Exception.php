<?php

namespace Appwrite\Extend;

use Utopia\Config\Config;

class Exception extends \Exception
{
    /**
     * Error Codes
     *
     * Naming the error types based on the following convention
     * <ENTITY>_<ERROR_TYPE>
     *
     * Appwrite has the following entities:
     * - General
     * - Users
     * - Teams
     * - Memberships
     * - Avatars
     * - Storage
     * - Functions
     * - Deployments
     * - Executions
     * - Collections
     * - Documents
     * - Attributes
     * - Indexes
     * - Projects
     * - Webhooks
     * - Keys
     * - Platform
     * - Domain
     * - GraphQL
     * - Migrations
     */

    /** General */
    public const GENERAL_UNKNOWN                   = 'general_unknown';
    public const GENERAL_MOCK                      = 'general_mock';
    public const GENERAL_ACCESS_FORBIDDEN          = 'general_access_forbidden';
    public const GENERAL_UNKNOWN_ORIGIN            = 'general_unknown_origin';
    public const GENERAL_API_DISABLED              = 'general_api_disabled';
    public const GENERAL_SERVICE_DISABLED          = 'general_service_disabled';
    public const GENERAL_UNAUTHORIZED_SCOPE        = 'general_unauthorized_scope';
    public const GENERAL_RATE_LIMIT_EXCEEDED       = 'general_rate_limit_exceeded';
    public const GENERAL_SMTP_DISABLED             = 'general_smtp_disabled';
    public const GENERAL_PHONE_DISABLED            = 'general_phone_disabled';
    public const GENERAL_ARGUMENT_INVALID          = 'general_argument_invalid';
    public const GENERAL_QUERY_LIMIT_EXCEEDED      = 'general_query_limit_exceeded';
    public const GENERAL_QUERY_INVALID             = 'general_query_invalid';
    public const GENERAL_ROUTE_NOT_FOUND           = 'general_route_not_found';
    public const GENERAL_CURSOR_NOT_FOUND          = 'general_cursor_not_found';
    public const GENERAL_SERVER_ERROR              = 'general_server_error';
    public const GENERAL_PROTOCOL_UNSUPPORTED      = 'general_protocol_unsupported';
    public const GENERAL_CODES_DISABLED            = 'general_codes_disabled';
    public const GENERAL_USAGE_DISABLED            = 'general_usage_disabled';
    public const GENERAL_NOT_IMPLEMENTED           = 'general_not_implemented';
    public const GENERAL_INVALID_EMAIL             = 'general_invalid_email';
    public const GENERAL_INVALID_PHONE             = 'general_invalid_phone';
    public const GENERAL_REGION_ACCESS_DENIED      = 'general_region_access_denied';
    public const GENERAL_BAD_REQUEST               = 'general_bad_request';

    /** Users */
    public const USER_COUNT_EXCEEDED               = 'user_count_exceeded';
    public const USER_CONSOLE_COUNT_EXCEEDED       = 'user_console_count_exceeded';
    public const USER_JWT_INVALID                  = 'user_jwt_invalid';
    public const USER_ALREADY_EXISTS               = 'user_already_exists';
    public const USER_BLOCKED                      = 'user_blocked';
    public const USER_INVALID_TOKEN                = 'user_invalid_token';
    public const USER_PASSWORD_RESET_REQUIRED      = 'user_password_reset_required';
    public const USER_EMAIL_NOT_WHITELISTED        = 'user_email_not_whitelisted';
    public const USER_IP_NOT_WHITELISTED           = 'user_ip_not_whitelisted';
    public const USER_INVALID_CODE                 = 'user_invalid_code';
    public const USER_INVALID_CREDENTIALS          = 'user_invalid_credentials';
    public const USER_ANONYMOUS_CONSOLE_PROHIBITED = 'user_anonymous_console_prohibited';
    public const USER_SESSION_ALREADY_EXISTS       = 'user_session_already_exists';
    public const USER_NOT_FOUND                    = 'user_not_found';
    public const USER_PASSWORD_RECENTLY_USED       = 'password_recently_used';
    public const USER_PASSWORD_PERSONAL_DATA       = 'password_personal_data';
    public const USER_EMAIL_ALREADY_EXISTS         = 'user_email_already_exists';
    public const USER_PASSWORD_MISMATCH            = 'user_password_mismatch';
    public const USER_SESSION_NOT_FOUND            = 'user_session_not_found';
    public const USER_IDENTITY_NOT_FOUND           = 'user_identity_not_found';
    public const USER_UNAUTHORIZED                 = 'user_unauthorized';
    public const USER_AUTH_METHOD_UNSUPPORTED      = 'user_auth_method_unsupported';
    public const USER_PHONE_ALREADY_EXISTS         = 'user_phone_already_exists';
    public const USER_PHONE_NOT_FOUND              = 'user_phone_not_found';
    public const USER_PHONE_NOT_VERIFIED           = 'user_phone_not_verified';
    public const USER_EMAIL_NOT_FOUND              = 'user_email_not_found';
    public const USER_EMAIL_NOT_VERIFIED           = 'user_email_not_verified';
    public const USER_MISSING_ID                   = 'user_missing_id';
    public const USER_MORE_FACTORS_REQUIRED        = 'user_more_factors_required';
    public const USER_INVALID_CHALLENGE            = 'user_invalid_challenge';
    public const USER_AUTHENTICATOR_NOT_FOUND      = 'user_authenticator_not_found';
    public const USER_AUTHENTICATOR_ALREADY_VERIFIED = 'user_authenticator_already_verified';
    public const USER_RECOVERY_CODES_ALREADY_EXISTS = 'user_recovery_codes_already_exists';
    public const USER_RECOVERY_CODES_NOT_FOUND     = 'user_recovery_codes_not_found';
    public const USER_CHALLENGE_REQUIRED           = 'user_challenge_required';
    public const USER_OAUTH2_BAD_REQUEST           = 'user_oauth2_bad_request';
    public const USER_OAUTH2_UNAUTHORIZED          = 'user_oauth2_unauthorized';
    public const USER_OAUTH2_PROVIDER_ERROR        = 'user_oauth2_provider_error';
    public const USER_EMAIL_ALREADY_VERIFIED       = 'user_email_already_verified';
    public const USER_PHONE_ALREADY_VERIFIED       = 'user_phone_already_verified';
    public const USER_DELETION_PROHIBITED          = 'user_deletion_prohibited';
    public const USER_TARGET_NOT_FOUND             = 'user_target_not_found';
    public const USER_TARGET_ALREADY_EXISTS        = 'user_target_already_exists';
    public const USER_API_KEY_AND_SESSION_SET      = 'user_key_and_session_set';

    /** Teams */
    public const TEAM_NOT_FOUND                    = 'team_not_found';
    public const TEAM_INVITE_ALREADY_EXISTS        = 'team_invite_already_exists';
    public const TEAM_INVITE_NOT_FOUND             = 'team_invite_not_found';
    public const TEAM_INVALID_SECRET               = 'team_invalid_secret';
    public const TEAM_MEMBERSHIP_MISMATCH          = 'team_membership_mismatch';
    public const TEAM_INVITE_MISMATCH              = 'team_invite_mismatch';
    public const TEAM_ALREADY_EXISTS               = 'team_already_exists';

    /** Membership */
    public const MEMBERSHIP_NOT_FOUND              = 'membership_not_found';
    public const MEMBERSHIP_ALREADY_CONFIRMED      = 'membership_already_confirmed';

    /** Avatars */
    public const AVATAR_SET_NOT_FOUND              = 'avatar_set_not_found';
    public const AVATAR_NOT_FOUND                  = 'avatar_not_found';
    public const AVATAR_IMAGE_NOT_FOUND            = 'avatar_image_not_found';
    public const AVATAR_REMOTE_URL_FAILED          = 'avatar_remote_url_failed';
    public const AVATAR_ICON_NOT_FOUND             = 'avatar_icon_not_found';

    /** Storage */
    public const STORAGE_FILE_ALREADY_EXISTS       = 'storage_file_already_exists';
    public const STORAGE_FILE_NOT_FOUND            = 'storage_file_not_found';
    public const STORAGE_DEVICE_NOT_FOUND          = 'storage_device_not_found';
    public const STORAGE_FILE_EMPTY                = 'storage_file_empty';
    public const STORAGE_FILE_TYPE_UNSUPPORTED     = 'storage_file_type_unsupported';
    public const STORAGE_INVALID_FILE_SIZE         = 'storage_invalid_file_size';
    public const STORAGE_INVALID_FILE              = 'storage_invalid_file';
    public const STORAGE_BUCKET_ALREADY_EXISTS     = 'storage_bucket_already_exists';
    public const STORAGE_BUCKET_NOT_FOUND          = 'storage_bucket_not_found';
    public const STORAGE_INVALID_CONTENT_RANGE     = 'storage_invalid_content_range';
    public const STORAGE_INVALID_RANGE             = 'storage_invalid_range';
    public const STORAGE_INVALID_APPWRITE_ID       = 'storage_invalid_appwrite_id';
    public const STORAGE_FILE_NOT_PUBLIC           = 'storage_file_not_public';

    /** VCS */
    public const INSTALLATION_NOT_FOUND            = 'installation_not_found';
    public const PROVIDER_REPOSITORY_NOT_FOUND     = 'provider_repository_not_found';
    public const REPOSITORY_NOT_FOUND              = 'repository_not_found';
    public const PROVIDER_CONTRIBUTION_CONFLICT    = 'provider_contribution_conflict';
    public const GENERAL_PROVIDER_FAILURE          = 'general_provider_failure';

    /** Functions */
    public const FUNCTION_NOT_FOUND                = 'function_not_found';
    public const FUNCTION_RUNTIME_UNSUPPORTED      = 'function_runtime_unsupported';
    public const FUNCTION_ENTRYPOINT_MISSING       = 'function_entrypoint_missing';

    /** Deployments */
    public const DEPLOYMENT_NOT_FOUND              = 'deployment_not_found';

    /** Builds */
    public const BUILD_NOT_FOUND                   = 'build_not_found';
    public const BUILD_NOT_READY                   = 'build_not_ready';
    public const BUILD_IN_PROGRESS                 = 'build_in_progress';

    /** Execution */
    public const EXECUTION_NOT_FOUND               = 'execution_not_found';

    /** Databases */
    public const DATABASE_NOT_FOUND                = 'database_not_found';
    public const DATABASE_ALREADY_EXISTS           = 'database_already_exists';
    public const DATABASE_TIMEOUT                  = 'database_timeout';

    /** Collections */
    public const COLLECTION_NOT_FOUND              = 'collection_not_found';
    public const COLLECTION_ALREADY_EXISTS         = 'collection_already_exists';
    public const COLLECTION_LIMIT_EXCEEDED         = 'collection_limit_exceeded';

    /** Documents */
    public const DOCUMENT_NOT_FOUND                = 'document_not_found';
    public const DOCUMENT_INVALID_STRUCTURE        = 'document_invalid_structure';
    public const DOCUMENT_MISSING_DATA             = 'document_missing_data';
    public const DOCUMENT_MISSING_PAYLOAD          = 'document_missing_payload';
    public const DOCUMENT_ALREADY_EXISTS           = 'document_already_exists';
    public const DOCUMENT_UPDATE_CONFLICT          = 'document_update_conflict';
    public const DOCUMENT_DELETE_RESTRICTED        = 'document_delete_restricted';

    /** Attribute */
    public const ATTRIBUTE_NOT_FOUND               = 'attribute_not_found';
    public const ATTRIBUTE_UNKNOWN                 = 'attribute_unknown';
    public const ATTRIBUTE_NOT_AVAILABLE           = 'attribute_not_available';
    public const ATTRIBUTE_FORMAT_UNSUPPORTED      = 'attribute_format_unsupported';
    public const ATTRIBUTE_DEFAULT_UNSUPPORTED     = 'attribute_default_unsupported';
    public const ATTRIBUTE_ALREADY_EXISTS          = 'attribute_already_exists';
    public const ATTRIBUTE_LIMIT_EXCEEDED          = 'attribute_limit_exceeded';
    public const ATTRIBUTE_VALUE_INVALID           = 'attribute_value_invalid';
    public const ATTRIBUTE_TYPE_INVALID            = 'attribute_type_invalid';

    /** Indexes */
    public const INDEX_NOT_FOUND                   = 'index_not_found';
    public const INDEX_LIMIT_EXCEEDED              = 'index_limit_exceeded';
    public const INDEX_ALREADY_EXISTS              = 'index_already_exists';
    public const INDEX_INVALID                     = 'index_invalid';

    /** Projects */
    public const PROJECT_NOT_FOUND                 = 'project_not_found';
    public const PROJECT_PROVIDER_DISABLED         = 'project_provider_disabled';
    public const PROJECT_PROVIDER_UNSUPPORTED      = 'project_provider_unsupported';
    public const PROJECT_ALREADY_EXISTS            = 'project_already_exists';
    public const PROJECT_INVALID_SUCCESS_URL       = 'project_invalid_success_url';
    public const PROJECT_INVALID_FAILURE_URL       = 'project_invalid_failure_url';
    public const PROJECT_RESERVED_PROJECT          = 'project_reserved_project';
    public const PROJECT_KEY_EXPIRED               = 'project_key_expired';

    public const PROJECT_SMTP_CONFIG_INVALID       = 'project_smtp_config_invalid';

    public const PROJECT_TEMPLATE_DEFAULT_DELETION = 'project_template_default_deletion';

    public const PROJECT_REGION_UNSUPPORTED        = 'project_region_unsupported';

    /** Webhooks */
    public const WEBHOOK_NOT_FOUND                 = 'webhook_not_found';

    /** Router */
    public const ROUTER_HOST_NOT_FOUND             = 'router_host_not_found';
    public const ROUTER_DOMAIN_NOT_CONFIGURED      = 'router_domain_not_configured';

    /** Proxy */
    public const RULE_RESOURCE_NOT_FOUND           = 'rule_resource_not_found';
    public const RULE_NOT_FOUND                    = 'rule_not_found';
    public const RULE_ALREADY_EXISTS               = 'rule_already_exists';
    public const RULE_VERIFICATION_FAILED          = 'rule_verification_failed';

    /** Keys */
    public const KEY_NOT_FOUND                     = 'key_not_found';

    /** Variables */
    public const VARIABLE_NOT_FOUND                = 'variable_not_found';
    public const VARIABLE_ALREADY_EXISTS           = 'variable_already_exists';

    /** Platform */
    public const PLATFORM_NOT_FOUND                = 'platform_not_found';

    /** GraphqQL */
    public const GRAPHQL_NO_QUERY                  = 'graphql_no_query';
    public const GRAPHQL_TOO_MANY_QUERIES          = 'graphql_too_many_queries';

    /** Migrations */
    public const MIGRATION_NOT_FOUND               = 'migration_not_found';
    public const MIGRATION_ALREADY_EXISTS          = 'migration_already_exists';
    public const MIGRATION_IN_PROGRESS             = 'migration_in_progress';
    public const MIGRATION_PROVIDER_ERROR          = 'migration_provider_error';

    /** Realtime */
    public const REALTIME_MESSAGE_FORMAT_INVALID   = 'realtime_message_format_invalid';
    public const REALTIME_TOO_MANY_MESSAGES        = 'realtime_too_many_messages';
    public const REALTIME_POLICY_VIOLATION         = 'realtime_policy_violation';

    /** Health */
    public const HEALTH_QUEUE_SIZE_EXCEEDED        = 'health_queue_size_exceeded';
    public const HEALTH_CERTIFICATE_EXPIRED        = 'health_certificate_expired';
    public const HEALTH_INVALID_HOST               = 'health_invalid_host';

    /** Provider */
    public const PROVIDER_NOT_FOUND                = 'provider_not_found';
    public const PROVIDER_ALREADY_EXISTS           = 'provider_already_exists';
    public const PROVIDER_INCORRECT_TYPE           = 'provider_incorrect_type';
    public const PROVIDER_MISSING_CREDENTIALS      = 'provider_missing_credentials';

    /** Topic */
    public const TOPIC_NOT_FOUND                   = 'topic_not_found';
    public const TOPIC_ALREADY_EXISTS              = 'topic_already_exists';

    /** Subscriber */
    public const SUBSCRIBER_NOT_FOUND              = 'subscriber_not_found';
    public const SUBSCRIBER_ALREADY_EXISTS         = 'subscriber_already_exists';

    /** Message */
    public const MESSAGE_NOT_FOUND                 = 'message_not_found';
    public const MESSAGE_MISSING_TARGET            = 'message_missing_target';
    public const MESSAGE_ALREADY_SENT              = 'message_already_sent';
    public const MESSAGE_ALREADY_PROCESSING        = 'message_already_processing';
    public const MESSAGE_ALREADY_FAILED            = 'message_already_failed';
    public const MESSAGE_ALREADY_SCHEDULED         = 'message_already_scheduled';
    public const MESSAGE_TARGET_NOT_EMAIL          = 'message_target_not_email';
    public const MESSAGE_TARGET_NOT_SMS            = 'message_target_not_sms';
    public const MESSAGE_TARGET_NOT_PUSH           = 'message_target_not_push';
    public const MESSAGE_MISSING_SCHEDULE          = 'message_missing_schedule';

    /** Targets */
    public const TARGET_PROVIDER_INVALID_TYPE       = 'target_provider_invalid_type';

    /** Schedules */
    public const SCHEDULE_NOT_FOUND                = 'schedule_not_found';


    protected string $type = '';
    protected array $errors = [];
    protected bool $publish;

    public function __construct(string $type = Exception::GENERAL_UNKNOWN, string $message = null, int $code = null, \Throwable $previous = null)
    {
        $this->errors = Config::getParam('errors');
        $this->type = $type;
        $this->code = $code ?? $this->errors[$type]['code'];
        $this->message = $message ?? $this->errors[$type]['description'];

        $this->publish = $this->errors[$type]['publish'] ?? ($this->code >= 500);

        parent::__construct($this->message, $this->code, $previous);
    }

    /**
     * Get the type of the exception.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set the type of the exception.
     *
     * @param string $type
     *
     * @return void
     */
    public function setType(string $type): void
    {
        $this->type = $type;
    }

    /**
     * Check whether the log is publishable for the exception.
     *
     * @return bool
     */
    public function isPublishable(): bool
    {
        return $this->publish;
    }
}
